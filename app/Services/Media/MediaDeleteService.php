<?php

namespace App\Services\Media;

use App\Exceptions\WorkflowConflictException;
use App\Models\AnnotationItem;
use App\Models\MediaAsset;
use App\Models\ModelRun;
use App\Models\ProcessingJob;
use App\Models\TrainingDatasetItem;
use App\Models\TreeObservation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class MediaDeleteService
{
    public function __construct(
        private readonly ScopedMediaAssetService $scoped,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function delete(
        User $actor,
        string $mediaId,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): void {
        DB::transaction(function () use ($actor, $mediaId, $ipAddress, $userAgent, $requestId): void {
            $asset = $this->scoped->findForUpdate($actor, $mediaId);
            $dependencies = $this->dependencies($asset);

            if ($dependencies !== []) {
                throw new WorkflowConflictException(
                    'Media with downstream dependencies cannot be archived.',
                    ['dependencies' => $dependencies],
                );
            }

            $old = $asset->only([
                'media_asset_id', 'flight_session_id', 'file_name',
                'quality_status', 'processing_status', 'sync_version',
            ]);
            $asset->sync_version = ((int) $asset->sync_version) + 1;
            $asset->save();
            $asset->delete();

            $this->auditLogger->record(
                action: 'media.delete',
                tableName: 'media_assets',
                recordId: $asset->media_asset_id,
                userId: $actor->user_id,
                oldValues: $old,
                newValues: [
                    'sync_version' => (int) $asset->sync_version,
                    'deleted_at' => $asset->deleted_at?->toIso8601String(),
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );
        });
    }

    /** @return list<string> */
    private function dependencies(MediaAsset $asset): array
    {
        $dependencies = [];

        if (in_array($asset->processing_status, ['queued', 'processing'], true)) {
            $dependencies[] = 'active_processing';
        }

        $queries = [
            'model_runs' => ModelRun::query()->where('input_media_id', $asset->media_asset_id),
            'tree_observations' => TreeObservation::query()->where('source_media_id', $asset->media_asset_id),
            'training_dataset_items' => TrainingDatasetItem::query()->where('media_asset_id', $asset->media_asset_id),
            'annotation_items' => AnnotationItem::query()->where('media_asset_id', $asset->media_asset_id),
        ];

        foreach ($queries as $name => $query) {
            if ($query->exists()) {
                $dependencies[] = $name;
            }
        }

        $hasActiveJob = ProcessingJob::query()
            ->whereIn('job_status', ['queued', 'running'])
            ->get(['input_summary'])
            ->contains(fn (ProcessingJob $job): bool => in_array(
                $asset->media_asset_id,
                $job->input_summary['media_ids'] ?? [],
                true,
            ));

        if ($hasActiveJob && ! in_array('active_processing', $dependencies, true)) {
            $dependencies[] = 'active_processing';
        }

        return $dependencies;
    }
}
