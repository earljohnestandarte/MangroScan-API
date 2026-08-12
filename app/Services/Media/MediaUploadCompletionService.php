<?php

namespace App\Services\Media;

use App\Contracts\Media\PrivateObjectInspector;
use App\Exceptions\WorkflowConflictException;
use App\Models\MediaAsset;
use App\Models\MediaUploadSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class MediaUploadCompletionService
{
    public function __construct(
        private readonly PrivateObjectInspector $objectInspector,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param array<string, mixed> $data */
    public function complete(
        MediaUploadSession $session,
        User $actor,
        string $idempotencyKey,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): MediaAsset {
        $fingerprint = hash('sha256', json_encode($this->canonicalPayload($session, $data), JSON_THROW_ON_ERROR));

        $current = DB::transaction(function () use ($session, $actor, $idempotencyKey, $fingerprint): MediaUploadSession {
            $this->lockIdempotency($actor->user_id, $idempotencyKey);
            $this->assertKeyAvailableForSession($actor->user_id, $session->upload_id, $idempotencyKey);

            $locked = MediaUploadSession::query()->lockForUpdate()->findOrFail($session->upload_id);
            if ($locked->initiated_by_user_id !== $actor->user_id) {
                abort(404);
            }
            if ($locked->upload_status === 'completed') {
                $this->assertSameCompletion($locked, $idempotencyKey, $fingerprint);

                return $locked;
            }
            if ($locked->upload_status !== 'initiated') {
                throw new WorkflowConflictException(
                    'This upload session cannot be finalized.',
                    ['upload_status' => $locked->upload_status],
                );
            }
            if ($locked->expires_at->isPast()) {
                throw new WorkflowConflictException(
                    'This upload session has expired.',
                    ['upload_status' => 'expired'],
                );
            }

            return $locked;
        });

        if ($current->upload_status === 'completed') {
            return MediaAsset::query()->withCaptureLocationGeoJson()->findOrFail($current->media_asset_id);
        }

        $inspection = $this->objectInspector->inspect($current->storage_disk, $current->storage_key);
        if ($inspection['size'] !== $current->file_size_bytes) {
            throw new WorkflowConflictException(
                'The uploaded object size does not match the initiated upload.',
                ['expected_size_bytes' => $current->file_size_bytes, 'actual_size_bytes' => $inspection['size']],
            );
        }

        $expectedChecksum = $data['checksum_sha256'] ?? $current->checksum_sha256;
        if ($current->checksum_sha256 !== null
            && isset($data['checksum_sha256'])
            && ! hash_equals($current->checksum_sha256, $data['checksum_sha256'])) {
            throw new WorkflowConflictException(
                'The completion checksum differs from the initiated upload.',
                ['checksum_matches_initiation' => false],
            );
        }
        if ($expectedChecksum !== null && ! hash_equals($expectedChecksum, $inspection['checksum_sha256'])) {
            throw new WorkflowConflictException(
                'The uploaded object checksum does not match.',
                ['checksum_matches_object' => false],
            );
        }

        return DB::transaction(function () use ($current, $actor, $idempotencyKey, $fingerprint, $inspection, $ipAddress, $userAgent, $requestId): MediaAsset {
            $this->lockIdempotency($actor->user_id, $idempotencyKey);
            $this->assertKeyAvailableForSession($actor->user_id, $current->upload_id, $idempotencyKey);

            $locked = MediaUploadSession::query()->lockForUpdate()->findOrFail($current->upload_id);
            if ($locked->upload_status === 'completed') {
                $this->assertSameCompletion($locked, $idempotencyKey, $fingerprint);

                return MediaAsset::query()->withCaptureLocationGeoJson()->findOrFail($locked->media_asset_id);
            }
            if ($locked->upload_status !== 'initiated') {
                throw new WorkflowConflictException('This upload session cannot be finalized.', ['upload_status' => $locked->upload_status]);
            }
            if ($locked->expires_at->isPast()) {
                throw new WorkflowConflictException(
                    'This upload session has expired.',
                    ['upload_status' => 'expired'],
                );
            }

            $mediaId = (string) str()->uuid();
            $timestamp = now('UTC')->toIso8601String();
            $values = [
                'media_asset_id' => $mediaId,
                'flight_session_id' => $locked->flight_session_id,
                'uploaded_by_user_id' => $locked->initiated_by_user_id,
                'file_name' => $locked->file_name,
                'file_type' => $locked->file_type,
                'mime_type' => $locked->mime_type,
                'file_size_bytes' => $locked->file_size_bytes,
                'storage_key' => $locked->storage_key,
                'checksum_sha256' => $inspection['checksum_sha256'],
                'capture_location' => DB::getDriverName() === 'pgsql' ? null : $this->captureLocation($locked),
                'captured_at' => $locked->captured_at?->utc()->toIso8601String(),
                'metadata' => $this->json($locked->metadata),
                'quality_status' => 'pending',
                'processing_status' => 'pending',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            if (DB::getDriverName() === 'pgsql') {
                DB::table('media_assets')->insertUsing(
                    array_keys($values),
                    DB::query()
                        ->from('media_upload_sessions')
                        ->where('upload_id', $locked->upload_id)
                        ->select(array_map(
                            static fn (string $column) => $column === 'capture_location'
                                ? DB::raw('capture_location')
                                : DB::raw('?'),
                            array_keys($values),
                        ))
                        ->addBinding(array_values(array_filter(
                            $values,
                            static fn (mixed $value, string $column): bool => $column !== 'capture_location',
                            ARRAY_FILTER_USE_BOTH,
                        )), 'select'),
                );
            } else {
                DB::table('media_assets')->insert($values);
            }

            DB::table('media_upload_sessions')->where('upload_id', $locked->upload_id)->update([
                'completion_idempotency_key' => $idempotencyKey,
                'completion_fingerprint' => $fingerprint,
                'upload_status' => 'completed',
                'completed_at' => $timestamp,
                'media_asset_id' => $mediaId,
                'updated_at' => $timestamp,
            ]);

            $this->auditLogger->record(
                action: 'media.upload.complete',
                tableName: 'media_assets',
                recordId: $mediaId,
                userId: $actor->user_id,
                oldValues: null,
                newValues: [
                    'media_asset_id' => $mediaId,
                    'upload_id' => $locked->upload_id,
                    'flight_session_id' => $locked->flight_session_id,
                    'storage_key' => $locked->storage_key,
                    'file_size_bytes' => $locked->file_size_bytes,
                    'checksum_sha256' => $inspection['checksum_sha256'],
                    'quality_status' => 'pending',
                    'processing_status' => 'pending',
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return MediaAsset::query()->withCaptureLocationGeoJson()->findOrFail($mediaId);
        });
    }

    private function assertSameCompletion(MediaUploadSession $session, string $key, string $fingerprint): void
    {
        if ($session->completion_idempotency_key !== $key
            || $session->completion_fingerprint === null
            || ! hash_equals($session->completion_fingerprint, $fingerprint)) {
            throw new WorkflowConflictException(
                'This upload session was already completed with another request.',
                ['upload_status' => 'completed'],
            );
        }
    }

    private function assertKeyAvailableForSession(string $userId, string $uploadId, string $key): void
    {
        $usedByAnotherSession = MediaUploadSession::query()
            ->where('initiated_by_user_id', $userId)
            ->where('completion_idempotency_key', $key)
            ->where('upload_id', '<>', $uploadId)
            ->exists();

        if ($usedByAnotherSession) {
            throw new WorkflowConflictException(
                'This idempotency key was already used for another upload completion.',
                ['idempotency_key' => $key],
            );
        }
    }

    private function lockIdempotency(string $userId, string $key): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$userId.'|'.$key]);
        }
    }

    /** @param array<string, mixed> $data */
    private function canonicalPayload(MediaUploadSession $session, array $data): array
    {
        if (isset($data['parts'])) {
            usort($data['parts'], fn (array $a, array $b): int => $a['part_number'] <=> $b['part_number']);
        }
        ksort($data);

        return ['upload_id' => $session->upload_id, ...$data];
    }

    private function captureLocation(MediaUploadSession $session): ?string
    {
        $value = $session->capture_location;

        return $value === null || is_string($value) ? $value : $this->json($value);
    }

    private function json(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
