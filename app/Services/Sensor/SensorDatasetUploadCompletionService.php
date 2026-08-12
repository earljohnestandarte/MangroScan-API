<?php

namespace App\Services\Sensor;

use App\Contracts\Media\PrivateObjectInspector;
use App\Exceptions\WorkflowConflictException;
use App\Models\SensorDataset;
use App\Models\SensorDatasetUploadSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class SensorDatasetUploadCompletionService
{
    public function __construct(
        private readonly PrivateObjectInspector $inspector,
        private readonly AuditLogger $audit,
    ) {}

    public function complete(
        SensorDatasetUploadSession $session,
        User $actor,
        string $key,
        ?string $checksum,
        ?string $ip,
        ?string $agent,
        ?string $requestId,
    ): SensorDataset {
        $fingerprint = hash('sha256', json_encode([
            'upload_id' => $session->upload_id,
            'checksum_sha256' => $checksum,
        ], JSON_THROW_ON_ERROR));

        $locked = DB::transaction(function () use ($session, $actor, $key, $fingerprint): SensorDatasetUploadSession {
            $this->lock($actor->user_id, $key);
            $this->assertKeyAvailableForSession($actor->user_id, $session->upload_id, $key);
            $current = SensorDatasetUploadSession::query()->lockForUpdate()->findOrFail($session->upload_id);
            if ($current->initiated_by_user_id !== $actor->user_id) {
                abort(404);
            }
            if ($current->upload_status === 'completed') {
                $this->assertSameCompletion($current, $key, $fingerprint);

                return $current;
            }
            if ($current->upload_status !== 'initiated' || $current->expires_at->isPast()) {
                throw new WorkflowConflictException(
                    'This sensor upload cannot be finalized.',
                    ['upload_status' => $current->expires_at->isPast() ? 'expired' : $current->upload_status],
                );
            }

            return $current;
        });
        if ($locked->upload_status === 'completed') {
            return $this->dataset($locked->sensor_dataset_id);
        }

        $inspection = $this->inspector->inspect($locked->storage_disk, $locked->storage_key);
        if ($inspection['size'] !== $locked->file_size_bytes) {
            throw new WorkflowConflictException('The uploaded object size does not match.', [
                'expected_size_bytes' => $locked->file_size_bytes,
                'actual_size_bytes' => $inspection['size'],
            ]);
        }
        if ($checksum !== null && ! hash_equals($checksum, $inspection['checksum_sha256'])) {
            throw new WorkflowConflictException(
                'The uploaded object checksum does not match.',
                ['checksum_matches_object' => false],
            );
        }

        return DB::transaction(function () use ($locked, $actor, $key, $fingerprint, $inspection, $ip, $agent, $requestId): SensorDataset {
            $this->lock($actor->user_id, $key);
            $this->assertKeyAvailableForSession($actor->user_id, $locked->upload_id, $key);
            $current = SensorDatasetUploadSession::query()->lockForUpdate()->findOrFail($locked->upload_id);
            if ($current->upload_status === 'completed') {
                $this->assertSameCompletion($current, $key, $fingerprint);

                return $this->dataset($current->sensor_dataset_id);
            }
            if ($current->upload_status !== 'initiated' || $current->expires_at->isPast()) {
                throw new WorkflowConflictException(
                    'This sensor upload cannot be finalized.',
                    ['upload_status' => $current->expires_at->isPast() ? 'expired' : $current->upload_status],
                );
            }

            $id = (string) str()->uuid();
            $now = now('UTC')->toIso8601String();
            DB::table('sensor_datasets')->insert([
                'sensor_dataset_id' => $id, 'flight_session_id' => $current->flight_session_id,
                'sensor_id' => $current->sensor_id, 'dataset_type' => $current->dataset_type,
                'file_name' => $current->file_name, 'storage_key' => $current->storage_key,
                'file_format' => $current->file_format, 'spatial_reference' => $current->spatial_reference,
                'metadata' => $current->metadata === null ? null : json_encode($current->metadata, JSON_THROW_ON_ERROR),
                'quality_status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('sensor_dataset_upload_sessions')->where('upload_id', $current->upload_id)->update([
                'completion_idempotency_key' => $key, 'completion_fingerprint' => $fingerprint,
                'checksum_sha256' => $inspection['checksum_sha256'], 'upload_status' => 'completed',
                'completed_at' => $now, 'sensor_dataset_id' => $id, 'updated_at' => $now,
            ]);
            $this->audit->record('sensor_dataset.upload.complete', 'sensor_datasets', $id, $actor->user_id, null, [
                'sensor_dataset_id' => $id, 'upload_id' => $current->upload_id,
                'flight_session_id' => $current->flight_session_id, 'sensor_id' => $current->sensor_id,
                'storage_key' => $current->storage_key, 'checksum_sha256' => $inspection['checksum_sha256'],
            ], $ip, $agent, $requestId);

            return $this->dataset($id);
        });
    }

    private function dataset(string $id): SensorDataset
    {
        return SensorDataset::query()->select([
            'sensor_dataset_id', 'flight_session_id', 'sensor_id', 'dataset_type',
            'file_name', 'file_format', 'recorded_start_at', 'recorded_end_at',
            'spatial_reference', 'metadata', 'quality_status', 'created_at', 'updated_at',
        ])->findOrFail($id);
    }

    private function assertSameCompletion(SensorDatasetUploadSession $session, string $key, string $fingerprint): void
    {
        if ($session->completion_idempotency_key !== $key
            || $session->completion_fingerprint === null
            || ! hash_equals($session->completion_fingerprint, $fingerprint)) {
            throw new WorkflowConflictException('This upload was already completed with another request.');
        }
    }

    private function assertKeyAvailableForSession(string $userId, string $uploadId, string $key): void
    {
        $usedByAnotherSession = SensorDatasetUploadSession::query()
            ->where('initiated_by_user_id', $userId)
            ->where('completion_idempotency_key', $key)
            ->where('upload_id', '<>', $uploadId)
            ->exists();

        if ($usedByAnotherSession) {
            throw new WorkflowConflictException(
                'This idempotency key was already used for another sensor upload completion.',
                ['idempotency_key' => $key],
            );
        }
    }

    private function lock(string $user, string $key): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$user.'|sensor-complete|'.$key]);
        }
    }
}
