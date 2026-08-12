<?php

namespace App\Services\Media;

use App\Contracts\Media\PrivateUploadUrlIssuer;
use App\Exceptions\WorkflowConflictException;
use App\Models\FlightSession;
use App\Models\MediaUploadSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MediaUploadInitiationService
{
    public function __construct(
        private readonly PrivateUploadUrlIssuer $uploadUrlIssuer,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{session: MediaUploadSession, upload_url: string, upload_headers: array<string, string>}
     */
    public function initiate(
        User $actor,
        FlightSession $flight,
        string $idempotencyKey,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): array {
        $fingerprint = hash('sha256', json_encode($this->canonicalPayload($flight, $data), JSON_THROW_ON_ERROR));
        $disk = (string) config('mangroscan.media.disk');
        $ttlMinutes = max(1, (int) config('mangroscan.media.upload_url_ttl_minutes'));

        $session = DB::transaction(function () use ($actor, $flight, $idempotencyKey, $data, $fingerprint, $disk, $ttlMinutes, $ipAddress, $userAgent, $requestId): MediaUploadSession {
            $this->lockIdempotency($actor->user_id, $idempotencyKey);
            $existing = MediaUploadSession::query()
                ->where('initiated_by_user_id', $actor->user_id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof MediaUploadSession) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new WorkflowConflictException(
                        'This idempotency key was already used for another upload request.',
                        ['idempotency_key' => $idempotencyKey],
                    );
                }
                if ($existing->upload_status !== 'initiated') {
                    throw new WorkflowConflictException(
                        'This upload session can no longer be initiated.',
                        ['upload_status' => $existing->upload_status],
                    );
                }
                if ($existing->expires_at->isPast()) {
                    throw new WorkflowConflictException(
                        'This upload session has expired.',
                        ['upload_status' => 'expired'],
                    );
                }

                return $existing;
            }

            $currentFlight = FlightSession::query()->lockForUpdate()->findOrFail($flight->flight_session_id);
            if (! in_array($currentFlight->flight_status, ['flying', 'completed'], true)) {
                throw new WorkflowConflictException(
                    'Media uploads require a started or completed flight.',
                    ['current_status' => $currentFlight->flight_status],
                );
            }

            $uploadId = (string) Str::uuid();
            $extension = strtolower(pathinfo($data['file_name'], PATHINFO_EXTENSION));
            $storageKey = 'missions/'.$currentFlight->mission_id
                .'/flights/'.$currentFlight->flight_session_id
                .'/media/'.$uploadId.($extension === '' ? '' : '.'.$extension);
            $expiresAt = CarbonImmutable::now('UTC')->addMinutes($ttlMinutes);

            $createdAt = CarbonImmutable::now('UTC');
            DB::table('media_upload_sessions')->insert([
                'upload_id' => $uploadId,
                'flight_session_id' => $currentFlight->flight_session_id,
                'initiated_by_user_id' => $actor->user_id,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'storage_disk' => $disk,
                'storage_key' => $storageKey,
                'file_name' => $data['file_name'],
                'file_type' => $data['file_type'],
                'mime_type' => $data['mime_type'],
                'file_size_bytes' => $data['file_size_bytes'],
                'checksum_sha256' => $data['checksum_sha256'] ?? null,
                'capture_location' => DB::getDriverName() === 'pgsql' ? null : $this->json($data['capture_location'] ?? null),
                'captured_at' => isset($data['captured_at'])
                    ? CarbonImmutable::parse($data['captured_at'])->utc()->toIso8601String()
                    : null,
                'metadata' => $this->json($data['metadata'] ?? null),
                'upload_status' => 'initiated',
                'expires_at' => $expiresAt->toIso8601String(),
                'created_at' => $createdAt->toIso8601String(),
                'updated_at' => $createdAt->toIso8601String(),
            ]);
            if (DB::getDriverName() === 'pgsql' && isset($data['capture_location'])) {
                DB::statement(
                    'UPDATE media_upload_sessions SET capture_location = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) WHERE upload_id = ?',
                    [$this->json($data['capture_location']), $uploadId],
                );
            }

            $session = MediaUploadSession::query()->findOrFail($uploadId);

            $this->auditLogger->record(
                action: 'media.upload.initiate',
                tableName: 'media_upload_sessions',
                recordId: $session->upload_id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: [
                    'upload_id' => $session->upload_id,
                    'flight_session_id' => $session->flight_session_id,
                    'file_name' => $session->file_name,
                    'file_type' => $session->file_type,
                    'mime_type' => $session->mime_type,
                    'file_size_bytes' => $session->file_size_bytes,
                    'storage_key' => $session->storage_key,
                    'checksum_sha256' => $session->checksum_sha256,
                    'captured_at' => $session->captured_at?->utc()->toIso8601String(),
                    'upload_status' => $session->upload_status,
                    'expires_at' => $session->expires_at->utc()->toIso8601String(),
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $session;
        });

        $target = $this->uploadUrlIssuer->issue($session->storage_disk, $session->storage_key, $session->expires_at);

        return ['session' => $session, 'upload_url' => $target['url'], 'upload_headers' => $target['headers']];
    }

    /** @param array<string, mixed> $data */
    private function canonicalPayload(FlightSession $flight, array $data): array
    {
        ksort($data);

        return ['flight_session_id' => $flight->flight_session_id, ...$data];
    }

    private function lockIdempotency(string $userId, string $key): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$userId.'|'.$key]);
        }
    }

    private function json(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
