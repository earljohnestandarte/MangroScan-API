<?php

namespace App\Services\Processing;

use App\Exceptions\DownstreamServiceException;
use App\Exceptions\WorkflowConflictException;
use App\Models\AiModelVersion;
use App\Models\FlightSession;
use App\Models\MediaAsset;
use App\Models\ProcessingJob;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class ProcessingJobCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(
        SurveyMission $mission,
        ?FlightSession $flight,
        User $actor,
        string $idempotencyKey,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): ProcessingJob {
        $payload = $this->canonicalPayload($data);
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($mission, $flight, $actor, $idempotencyKey, $payload, $fingerprint, $ipAddress, $userAgent, $requestId): ProcessingJob {
            $this->lockIdempotency($actor->user_id, $idempotencyKey);
            $existing = ProcessingJob::query()
                ->where('created_by', $actor->user_id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ProcessingJob) {
                if ($existing->request_fingerprint === null || ! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new WorkflowConflictException(
                        'This idempotency key was already used for another processing request.',
                        ['idempotency_key' => $idempotencyKey],
                    );
                }

                return $existing;
            }

            $currentMission = SurveyMission::query()->lockForUpdate()->findOrFail($mission->mission_id);

            if ($flight instanceof FlightSession) {
                $currentFlight = FlightSession::query()->lockForUpdate()->findOrFail($flight->flight_session_id);
                if ($currentFlight->mission_id !== $currentMission->mission_id) {
                    throw new WorkflowConflictException(
                        'The flight does not belong to the requested mission.',
                        ['flight_session_id' => $currentFlight->flight_session_id],
                    );
                }
                if ($currentFlight->flight_status !== 'completed') {
                    throw new WorkflowConflictException(
                        'Processing requires a completed flight.',
                        ['flight_status' => $currentFlight->flight_status],
                    );
                }
            }

            $mediaIds = $payload['media_ids'];
            $media = MediaAsset::query()
                ->whereIn('media_asset_id', $mediaIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('media_asset_id');
            if ($media->count() !== count($mediaIds)) {
                abort(404);
            }

            foreach ($mediaIds as $mediaId) {
                /** @var MediaAsset $asset */
                $asset = $media->get($mediaId);
                if ($asset->flight?->mission_id !== $currentMission->mission_id
                    || ($flight instanceof FlightSession && $asset->flight_session_id !== $flight->flight_session_id)) {
                    abort(404);
                }
                if ($asset->flight->flight_status !== 'completed') {
                    throw new WorkflowConflictException(
                        'Processing requires media from completed flights.',
                        ['media_asset_id' => $asset->media_asset_id, 'flight_status' => $asset->flight->flight_status],
                    );
                }
                if (in_array($asset->quality_status, ['rejected', 'needs_recapture'], true)) {
                    throw new WorkflowConflictException(
                        'Rejected media cannot be queued for processing.',
                        ['media_asset_id' => $asset->media_asset_id, 'quality_status' => $asset->quality_status],
                    );
                }
                if (! in_array($asset->processing_status, ['pending', 'failed'], true)) {
                    throw new WorkflowConflictException(
                        'Media is already assigned to an active or completed processing workflow.',
                        ['media_asset_id' => $asset->media_asset_id, 'processing_status' => $asset->processing_status],
                    );
                }
            }

            $versions = $this->deployedVersions($payload['job_type']);
            $jobId = (string) str()->uuid();
            $timestamp = now('UTC')->toIso8601String();
            DB::table('processing_jobs')->insert([
                'processing_job_id' => $jobId,
                'mission_id' => $currentMission->mission_id,
                'flight_session_id' => $flight?->flight_session_id,
                'job_type' => $payload['job_type'],
                'job_status' => 'queued',
                'input_summary' => json_encode([
                    'media_ids' => $mediaIds,
                    'media_count' => count($mediaIds),
                    'parameters' => $payload['parameters'] ?? null,
                    'model_version_ids' => array_values($versions),
                ], JSON_THROW_ON_ERROR),
                'created_by' => $actor->user_id,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $runs = [];
            foreach ($mediaIds as $mediaId) {
                foreach ($versions as $runType => $modelVersionId) {
                    $runs[] = [
                        'model_run_id' => (string) str()->uuid(),
                        'processing_job_id' => $jobId,
                        'model_version_id' => $modelVersionId,
                        'run_type' => $runType,
                        'input_media_id' => $mediaId,
                        'parameters' => isset($payload['parameters'])
                            ? json_encode($payload['parameters'], JSON_THROW_ON_ERROR)
                            : null,
                        'run_status' => 'queued',
                        'created_at' => $timestamp,
                    ];
                }
            }
            DB::table('model_runs')->insert($runs);
            DB::table('media_assets')->whereIn('media_asset_id', $mediaIds)->update([
                'processing_status' => 'queued',
                'updated_at' => $timestamp,
            ]);

            $this->auditLogger->record(
                action: 'processing.create',
                tableName: 'processing_jobs',
                recordId: $jobId,
                userId: $actor->user_id,
                oldValues: null,
                newValues: [
                    'processing_job_id' => $jobId,
                    'mission_id' => $currentMission->mission_id,
                    'flight_session_id' => $flight?->flight_session_id,
                    'job_type' => $payload['job_type'],
                    'job_status' => 'queued',
                    'media_ids' => $mediaIds,
                    'model_version_ids' => array_values($versions),
                    'parameter_keys' => array_keys($payload['parameters'] ?? []),
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return ProcessingJob::query()->findOrFail($jobId);
        });
    }

    /** @return array<string, string> */
    private function deployedVersions(string $jobType): array
    {
        $required = match ($jobType) {
            'detection' => ['tree_detection' => 'tree_detector'],
            'classification' => ['species_classification' => 'species_classifier'],
            default => [
                'tree_detection' => 'tree_detector',
                'species_classification' => 'species_classifier',
            ],
        };
        $versions = [];
        foreach ($required as $runType => $modelType) {
            $version = AiModelVersion::query()
                ->select('ai_model_versions.*')
                ->join('ai_models', 'ai_models.model_id', '=', 'ai_model_versions.model_id')
                ->join('ai_services', 'ai_services.ai_service_id', '=', 'ai_models.ai_service_id')
                ->where('ai_model_versions.is_deployed', true)
                ->where('ai_models.model_type', $modelType)
                ->whereNull('ai_models.deleted_at')
                ->where('ai_services.enabled', true)
                ->where('ai_services.health_status', 'healthy')
                ->orderByDesc('ai_model_versions.created_at')
                ->orderByDesc('ai_model_versions.model_version_id')
                ->first();
            if (! $version instanceof AiModelVersion) {
                throw new DownstreamServiceException(
                    'A healthy deployed model required for this job is unavailable.',
                    503,
                    'SERVICE_UNAVAILABLE',
                );
            }
            $versions[$runType] = $version->model_version_id;
        }

        return $versions;
    }

    /** @param array<string, mixed> $data */
    private function canonicalPayload(array $data): array
    {
        sort($data['media_ids'], SORT_STRING);
        if (isset($data['parameters'])) {
            $data['parameters'] = $this->canonicalize($data['parameters']);
        }
        ksort($data);

        return $data;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function lockIdempotency(string $userId, string $key): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$userId.'|'.$key]);
        }
    }
}
