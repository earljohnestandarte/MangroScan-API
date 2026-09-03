<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiInferenceClient;
use App\Exceptions\DownstreamServiceException;
use App\Models\MediaAsset;
use App\Models\ProcessingJob;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiProcessingService
{
    public function __construct(private readonly AiInferenceClient $client) {}

    public function execute(string $jobId): void
    {
        $job = ProcessingJob::query()->with(['mission', 'modelRuns.modelVersion.model.service'])->findOrFail($jobId);
        if (in_array($job->job_status, ['completed', 'cancelled'], true)) {
            return;
        }

        $started = microtime(true);
        $requestId = $job->request_id ?: 'ai-'.$job->processing_job_id;
        try {
            DB::transaction(function () use ($job): void {
                $locked = ProcessingJob::query()->lockForUpdate()->findOrFail($job->processing_job_id);
                if (in_array($locked->job_status, ['completed', 'cancelled'], true)) {
                    return;
                }
                $locked->forceFill(['job_status' => 'running', 'started_at' => now('UTC'), 'error_message' => null])->save();
                DB::table('model_runs')->where('processing_job_id', $locked->processing_job_id)->where('run_status', 'queued')->update(['run_status' => 'running', 'started_at' => now('UTC')]);
                DB::table('media_assets')->whereIn('media_asset_id', $this->mediaIds($locked))->update(['processing_status' => 'processing', 'updated_at' => now('UTC')]);
            });

            $allRows = [];
            foreach ($this->mediaIds($job) as $mediaId) {
                $media = MediaAsset::query()->findOrFail($mediaId);
                $run = $job->modelRuns->firstWhere('input_media_id', $mediaId);
                if (! $run?->modelVersion?->model?->service) {
                    throw new DownstreamServiceException('A deployed AI service is unavailable for this job.', 503, 'SERVICE_UNAVAILABLE');
                }
                $capability = (string) ($job->input_summary['parameters']['capability'] ?? $this->defaultCapability($job->job_type, $media->file_type));
                $endpoint = $this->endpoint($capability);
                $payload = $this->client->infer($run->modelVersion->model->service->base_url, $this->decryptServiceKey($run->modelVersion->model->service), $endpoint, config('mangroscan.media.disk', 'local'), $media->storage_key, ['capability' => $capability, 'media_type' => $media->file_type], $requestId);
                $allRows = array_merge($allRows, $this->rows($payload));
                $this->persistRows($job, $media, $run, $payload, $this->rows($payload), $requestId);
                DB::table('model_runs')->where('model_run_id', $run->model_run_id)->update(['run_status' => 'completed', 'completed_at' => now('UTC')]);
                DB::table('media_assets')->where('media_asset_id', $mediaId)->update(['processing_status' => 'completed', 'updated_at' => now('UTC')]);
            }

            $summary = $this->summary($allRows, $requestId, (int) round((microtime(true) - $started) * 1000));
            DB::table('model_runs')->where('processing_job_id', $job->processing_job_id)->whereIn('run_status', ['queued', 'running'])->update(['run_status' => 'completed', 'completed_at' => now('UTC')]);
            $this->persistCountSummary($job, $summary, $job->modelRuns->first()?->model_run_id, $allRows);
            $job->forceFill(['job_status' => 'completed', 'completed_at' => now('UTC'), 'processing_time_ms' => $summary['processing_time_ms'], 'output_summary' => $summary])->save();
        } catch (Throwable $error) {
            $message = $error instanceof DownstreamServiceException ? $error->getMessage() : 'AI processing failed.';
            DB::transaction(function () use ($job, $message, $started): void {
                DB::table('processing_jobs')->where('processing_job_id', $job->processing_job_id)->update(['job_status' => 'failed', 'completed_at' => now('UTC'), 'processing_time_ms' => (int) round((microtime(true) - $started) * 1000), 'error_message' => $message, 'updated_at' => now('UTC')]);
                DB::table('model_runs')->where('processing_job_id', $job->processing_job_id)->whereIn('run_status', ['queued', 'running'])->update(['run_status' => 'failed', 'completed_at' => now('UTC')]);
                DB::table('media_assets')->whereIn('media_asset_id', $this->mediaIds($job))->update(['processing_status' => 'failed', 'updated_at' => now('UTC')]);
            });
            throw $error;
        }
    }

    /** @return list<string> */
    private function mediaIds(ProcessingJob $job): array
    {
        return array_values(array_filter($job->input_summary['media_ids'] ?? [], 'is_string'));
    }

    private function defaultCapability(string $jobType, string $mediaType): string
    {
        $group = match ($jobType) {
            'detection' => 'detection', 'classification' => 'classification', default => 'analysis'
        };

        return $group.'.'.$mediaType;
    }

    private function endpoint(string $capability): string
    {
        [$group, $media] = array_pad(explode('.', $capability, 2), 2, 'image');
        if (! in_array($group, ['detection', 'classification', 'analysis'], true) || ! in_array($media, ['image', 'video'], true)) {
            throw new DownstreamServiceException('Unsupported AI capability.', 422, 'INVALID_CAPABILITY');
        }
        $endpointGroup = match ($group) {
            'detection' => 'detect',
            'classification' => 'classify',
            'analysis' => 'analyze',
        };

        return "/api/v1/{$endpointGroup}/{$media}";
    }

    private function decryptServiceKey(object $service): string
    {
        $key = DB::getDriverName() === 'pgsql'
            ? DB::scalar('SELECT app.ai_service_encrypted_key(?)', [$service->getAttribute('ai_service_id')])
            : $service->getAttribute('encrypted_api_key');
        if (! is_string($key) || $key === '') {
            throw new DownstreamServiceException('The AI service credential is unavailable.', 503, 'SERVICE_UNAVAILABLE');
        }
        try {
            return Crypt::decryptString($key);
        } catch (Throwable) {
            throw new DownstreamServiceException('The AI service credential is unavailable.', 503, 'SERVICE_UNAVAILABLE');
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(array $payload): array
    {
        $rows = [];
        $add = function (mixed $detections, ?int $frame = null) use (&$rows): void {
            if (! is_array($detections)) {
                return;
            }
            foreach (array_values($detections) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $species = is_array($item['species'] ?? null) ? $item['species'] : [];
                $rows[] = ['frame_number' => $frame, 'detection_index' => is_int($item['id'] ?? null) ? $item['id'] : $index + 1, 'bounding_box' => $item['bbox'] ?? null, 'detection_confidence' => $this->confidence($item['confidence'] ?? null), 'predicted_species_name' => is_string($species['name'] ?? null) ? trim($species['name']) : null, 'species_confidence' => $this->confidence($species['confidence'] ?? null), 'result_metadata' => []];
            }
        };
        $add($payload['detections'] ?? null);
        foreach (is_array($payload['per_frame_statistics'] ?? null) ? $payload['per_frame_statistics'] : [] as $frame) {
            if (! is_array($frame)) {
                continue;
            } $add($frame['detections'] ?? null, is_int($frame['frame_number'] ?? null) ? $frame['frame_number'] : null);
        }
        if (is_array($payload['prediction'] ?? null)) {
            $rows[] = ['frame_number' => null, 'detection_index' => null, 'bounding_box' => null, 'detection_confidence' => null, 'predicted_species_name' => is_string($payload['prediction']['species'] ?? null) ? trim($payload['prediction']['species']) : null, 'species_confidence' => $this->confidence($payload['prediction']['confidence'] ?? null), 'result_metadata' => []];
        }

        return $rows;
    }

    private function confidence(mixed $value): ?float
    {
        $number = is_numeric($value) ? (float) $value : null;

        return $number !== null && is_finite($number) && $number >= 0 && $number <= 1 ? $number : null;
    }

    /** @param list<array<string,mixed>> $rows */
    private function persistRows(ProcessingJob $job, MediaAsset $media, object $run, array $payload, array $rows, string $requestId): void
    {
        foreach ($rows as $row) {
            $observationId = $this->persistObservation($job, $media, $run, $row, $requestId);
            DB::table('ai_inference_results')->insert(['ai_inference_result_id' => (string) str()->uuid(), 'processing_job_id' => $job->processing_job_id, 'model_run_id' => $run->model_run_id, 'mission_id' => $job->mission_id, 'flight_session_id' => $media->flight_session_id, 'source_media_id' => $media->media_asset_id, 'tree_observation_id' => $observationId, 'frame_number' => $row['frame_number'], 'detection_index' => $row['detection_index'], 'bounding_box' => $row['bounding_box'] ? json_encode($row['bounding_box'], JSON_THROW_ON_ERROR) : null, 'detection_confidence' => $row['detection_confidence'], 'predicted_species_name' => $row['predicted_species_name'], 'species_confidence' => $row['species_confidence'], 'result_metadata' => json_encode(['request_id' => $requestId, 'raw_keys' => array_keys($payload)], JSON_THROW_ON_ERROR), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->persistSpeciesClassification($observationId, $run->model_run_id, $row);
        }
    }

    /** @param array<string,mixed> $row */
    private function persistSpeciesClassification(?string $observationId, string $runId, array $row): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $observationId || ! $row['predicted_species_name'] || $row['species_confidence'] === null) {
            return;
        }
        $species = DB::table('mangrove_species')->where('scientific_name', $row['predicted_species_name'])->orWhere('common_name', $row['predicted_species_name'])->first();
        if (! $species) {
            return;
        }
        DB::table('species_classification_results')->insert(['classification_result_id' => (string) str()->uuid(), 'tree_observation_id' => $observationId, 'model_run_id' => $runId, 'predicted_species_id' => $species->species_id, 'confidence_score' => $row['species_confidence'], 'rank_no' => 1, 'classification_basis' => json_encode(['source' => 'fastapi', 'canonical_confidence' => true], JSON_THROW_ON_ERROR), 'is_final' => false, 'created_at' => now('UTC')]);
    }

    /** @param array<string,mixed> $summary */
    /** @param list<array<string,mixed>> $rows */
    private function persistCountSummary(ProcessingJob $job, array $summary, ?string $runId, array $rows): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $job->mission?->site_id) {
            return;
        }
        $values = [];
        foreach ($rows as $row) {
            foreach (['detection_confidence', 'species_confidence'] as $key) {
                if ($row[$key] !== null) {
                    $values[] = $row[$key];
                }
            }
        }
        $confidence = $values ? array_sum($values) / count($values) : null;
        DB::table('tree_count_summaries')->insert(['tree_count_summary_id' => (string) str()->uuid(), 'mission_id' => $job->mission_id, 'site_id' => $job->mission->site_id, 'model_run_id' => $runId, 'total_detected_trees' => (int) ($summary['tree_count'] ?? 0), 'validated_tree_count' => 0, 'estimated_density_per_hectare' => null, 'count_confidence_score' => $confidence, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }

    /** @param array<string,mixed> $row */
    private function persistObservation(ProcessingJob $job, MediaAsset $media, object $run, array $row, string $requestId): ?string
    {
        if ($row['bounding_box'] === null && $row['detection_confidence'] === null) {
            return null;
        }
        $coordinates = DB::getDriverName() === 'pgsql'
            ? DB::table('media_assets')->where('media_asset_id', $media->media_asset_id)->selectRaw('ST_X(capture_location) AS longitude, ST_Y(capture_location) AS latitude')->first()
            : null;
        if (! $coordinates || ! is_numeric($coordinates->longitude ?? null) || ! is_numeric($coordinates->latitude ?? null)) {
            return null;
        }
        $id = (string) str()->uuid();
        $code = 'AI-'.substr($media->media_asset_id, 0, 8).'-'.substr($requestId, 0, 16).'-'.($row['frame_number'] ?? 0).'-'.($row['detection_index'] ?? 1);
        if (DB::getDriverName() === 'pgsql') {
            DB::insert('INSERT INTO tree_observations (tree_observation_id, mission_id, flight_session_id, model_run_id, source_media_id, tree_code, tree_location, bounding_box, detection_confidence, validation_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?::jsonb, ?, ?, ?, ?)', [$id, $job->mission_id, $media->flight_session_id, $run->model_run_id, $media->media_asset_id, $code, (float) $coordinates->longitude, (float) $coordinates->latitude, $row['bounding_box'] ? json_encode($row['bounding_box'], JSON_THROW_ON_ERROR) : null, $row['detection_confidence'], 'unvalidated', now('UTC'), now('UTC')]);
        }

        return DB::getDriverName() === 'pgsql' ? $id : null;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function summary(array $rows, string $requestId, int $duration): array
    {
        $species = [];
        foreach ($rows as $row) {
            if (is_string($row['predicted_species_name'] ?? null) && $row['predicted_species_name'] !== '') {
                $species[$row['predicted_species_name']] = ($species[$row['predicted_species_name']] ?? 0) + 1;
            }
        }

return ['request_id' => $requestId, 'result_count' => count($rows), 'tree_count' => count(array_filter($rows, fn ($row) => $row['bounding_box'] !== null)), 'species_summary' => $species, 'processing_time_ms' => $duration];
    }
}
