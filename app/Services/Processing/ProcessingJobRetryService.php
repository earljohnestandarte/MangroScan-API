<?php

namespace App\Services\Processing;

use App\Exceptions\DownstreamServiceException;
use App\Exceptions\WorkflowConflictException;
use App\Models\AiModelVersion;
use App\Models\MediaAsset;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class ProcessingJobRetryService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function retry(ProcessingJob $source, User $actor, string $key, ?string $reason, ?string $ipAddress, ?string $userAgent, ?string $requestId): ProcessingJob
    {
        $fingerprint = hash('sha256', json_encode(['retry_of_job_id' => $source->processing_job_id, 'reason' => $reason], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($source, $actor, $key, $reason, $fingerprint, $ipAddress, $userAgent, $requestId): ProcessingJob {
            $this->lockIdempotency($actor->user_id, $key);
            $existing = ProcessingJob::query()->where('created_by', $actor->user_id)
                ->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing instanceof ProcessingJob) {
                if ($existing->request_fingerprint === null || ! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new WorkflowConflictException('This idempotency key was already used for another processing request.', ['idempotency_key' => $key]);
                }

                return $existing;
            }

            $failed = ProcessingJob::query()->with('modelRuns')->lockForUpdate()->findOrFail($source->processing_job_id);
            if ($failed->job_status !== 'failed') {
                throw new WorkflowConflictException('Only a failed processing job can be retried.', ['job_status' => $failed->job_status]);
            }
            if ($failed->modelRuns->isEmpty()) {
                throw new WorkflowConflictException('The failed job has no execution plan to retry.');
            }

            $versionIds = $failed->modelRuns->pluck('model_version_id')->unique()->values();
            $availableVersionCount = AiModelVersion::query()
                ->join('ai_models', 'ai_models.model_id', '=', 'ai_model_versions.model_id')
                ->join('ai_services', 'ai_services.ai_service_id', '=', 'ai_models.ai_service_id')
                ->whereIn('ai_model_versions.model_version_id', $versionIds)
                ->whereNull('ai_models.deleted_at')
                ->where('ai_services.enabled', true)
                ->where('ai_services.health_status', 'healthy')
                ->count();
            if ($availableVersionCount !== $versionIds->count()) {
                throw new DownstreamServiceException(
                    'A healthy AI service required by the failed job is unavailable.',
                    503,
                    'SERVICE_UNAVAILABLE',
                );
            }

            $mediaIds = $failed->modelRuns->pluck('input_media_id')->filter()->unique()->sort()->values();
            $media = MediaAsset::query()->whereIn('media_asset_id', $mediaIds)->lockForUpdate()->get()->keyBy('media_asset_id');
            if ($media->count() !== $mediaIds->count()) {
                abort(404);
            }
            foreach ($mediaIds as $mediaId) {
                $asset = $media->get($mediaId);
                if ($asset->flight?->mission_id !== $failed->mission_id) {
                    abort(404);
                }
                if ($asset->processing_status !== 'failed') {
                    throw new WorkflowConflictException('Retry inputs must remain in failed processing state.', ['media_asset_id' => $mediaId, 'processing_status' => $asset->processing_status]);
                }
            }

            $jobId = (string) str()->uuid();
            $timestamp = now('UTC')->toIso8601String();
            DB::table('processing_jobs')->insert([
                'processing_job_id' => $jobId, 'mission_id' => $failed->mission_id,
                'flight_session_id' => $failed->flight_session_id, 'job_type' => $failed->job_type,
                'job_status' => 'queued', 'input_summary' => json_encode($failed->input_summary, JSON_THROW_ON_ERROR),
                'created_by' => $actor->user_id, 'idempotency_key' => $key,
                'request_fingerprint' => $fingerprint, 'retry_of_job_id' => $failed->processing_job_id,
                'retry_reason' => $reason, 'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            $runs = $failed->modelRuns->sortBy(fn ($run) => $run->created_at?->getTimestamp().$run->model_run_id)
                ->map(fn ($run): array => [
                    'model_run_id' => (string) str()->uuid(), 'processing_job_id' => $jobId,
                    'model_version_id' => $run->model_version_id, 'run_type' => $run->run_type,
                    'input_media_id' => $run->input_media_id,
                    'parameters' => $run->parameters === null ? null : json_encode($run->parameters, JSON_THROW_ON_ERROR),
                    'run_status' => 'queued', 'created_at' => $timestamp,
                ])->values()->all();
            DB::table('model_runs')->insert($runs);
            DB::table('media_assets')->whereIn('media_asset_id', $mediaIds)->update(['processing_status' => 'queued', 'updated_at' => $timestamp]);

            $this->auditLogger->record('processing.retry', 'processing_jobs', $jobId, $actor->user_id, null, [
                'processing_job_id' => $jobId, 'retry_of_job_id' => $failed->processing_job_id,
                'job_status' => 'queued', 'reason' => $reason, 'media_ids' => $mediaIds->all(),
                'model_version_ids' => $versionIds->all(),
            ], $ipAddress, $userAgent, $requestId);

            return ProcessingJob::query()->findOrFail($jobId);
        });
    }

    private function lockIdempotency(string $userId, string $key): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$userId.'|'.$key]);
        }
    }
}
