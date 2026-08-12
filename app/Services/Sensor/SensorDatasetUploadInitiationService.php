<?php

namespace App\Services\Sensor;

use App\Contracts\Media\PrivateUploadUrlIssuer;
use App\Exceptions\WorkflowConflictException;
use App\Models\FlightSession;
use App\Models\SensorDatasetUploadSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SensorDatasetUploadInitiationService
{
    public function __construct(private readonly PrivateUploadUrlIssuer $issuer, private readonly AuditLogger $audit) {}

    public function initiate(User $actor, FlightSession $flight, string $key, array $data, ?string $ip, ?string $agent, ?string $requestId): array
    {
        $canonical = ['flight_session_id' => $flight->flight_session_id, ...$data];
        ksort($canonical);
        $fingerprint = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
        $disk = (string) config('mangroscan.media.disk');
        $ttl = max(1, (int) config('mangroscan.media.upload_url_ttl_minutes'));
        $session = DB::transaction(function () use ($actor, $flight, $key, $data, $fingerprint, $disk, $ttl, $ip, $agent, $requestId) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$actor->user_id.'|sensor|'.$key]);
            }
            $existing = SensorDatasetUploadSession::query()
                ->where('initiated_by_user_id', $actor->user_id)
                ->where('idempotency_key', $key)
                ->lockForUpdate()->first();
            if ($existing instanceof SensorDatasetUploadSession) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint) || $existing->upload_status !== 'initiated' || $existing->expires_at->isPast()) {
                    throw new WorkflowConflictException('This sensor upload request cannot be replayed.');
                }

                return $existing;
            }

            $current = FlightSession::query()->lockForUpdate()->findOrFail($flight->flight_session_id);
            if (! in_array($current->flight_status, ['flying', 'completed'], true)) {
                throw new WorkflowConflictException('Sensor uploads require a started or completed flight.');
            }
            $sensor = DB::table('drone_sensors')->where('sensor_id', $data['sensor_id'])
                ->where('drone_id', $current->drone_id)->first();
            if (! $sensor) {
                abort(404);
            }

            $id = (string) Str::uuid();
            $ext = strtolower(pathinfo($data['file_name'], PATHINFO_EXTENSION));
            $storageKey = 'missions/'.$current->mission_id.'/flights/'.$current->flight_session_id.'/sensor-datasets/'.$id.($ext === '' ? '' : '.'.$ext);
            $now = CarbonImmutable::now('UTC');
            DB::table('sensor_dataset_upload_sessions')->insert([
                'upload_id' => $id, 'flight_session_id' => $current->flight_session_id,
                'sensor_id' => $data['sensor_id'], 'initiated_by_user_id' => $actor->user_id,
                'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
                'storage_disk' => $disk, 'storage_key' => $storageKey,
                'file_name' => $data['file_name'], 'dataset_type' => $data['dataset_type'],
                'file_format' => $data['file_format'], 'file_size_bytes' => $data['file_size_bytes'],
                'spatial_reference' => $data['spatial_reference'] ?? null,
                'metadata' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_THROW_ON_ERROR) : null,
                'upload_status' => 'initiated', 'expires_at' => $now->addMinutes($ttl)->toIso8601String(),
                'created_at' => $now->toIso8601String(), 'updated_at' => $now->toIso8601String(),
            ]);
            $session = SensorDatasetUploadSession::query()->findOrFail($id);
            $this->audit->record('sensor_dataset.upload.initiate', 'sensor_dataset_upload_sessions', $id, $actor->user_id, null, ['upload_id' => $id, 'flight_session_id' => $current->flight_session_id, 'sensor_id' => $data['sensor_id'], 'dataset_type' => $data['dataset_type'], 'file_size_bytes' => $data['file_size_bytes'], 'storage_key' => $storageKey], $ip, $agent, $requestId);

            return $session;
        });
        $target = $this->issuer->issue($session->storage_disk, $session->storage_key, $session->expires_at);

        return ['session' => $session, 'upload_url' => $target['url']];
    }
}
