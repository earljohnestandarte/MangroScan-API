<?php

namespace App\Services\Training;

use App\Exceptions\WorkflowConflictException;
use App\Models\MangroveSpecies;
use App\Models\MediaAsset;
use App\Models\TrainingDataset;
use App\Models\TrainingDatasetItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TrainingDatasetItemCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(
        TrainingDataset $dataset,
        User $actor,
        array $data,
        bool $globalScope,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): TrainingDatasetItem {
        return DB::transaction(function () use ($dataset, $actor, $data, $globalScope, $ipAddress, $userAgent, $requestId): TrainingDatasetItem {
            $locked = TrainingDataset::query()->lockForUpdate()->findOrFail($dataset->training_dataset_id);

            if (! empty($data['media_id'])) {
                $media = MediaAsset::query();
                if (! $globalScope) {
                    $media->whereHas('flight.mission.site', function (Builder $query) use ($actor): void {
                        $query->where('organization_id', $actor->organization_id);
                    });
                }
                $media->findOrFail($data['media_id']);
            }

            if (! empty($data['species_id'])) {
                MangroveSpecies::query()->findOrFail($data['species_id']);
            }

            if ($locked->items()->where('label_file_path', $data['label_file_path'])->exists()) {
                throw new WorkflowConflictException('This label file is already attached to the dataset.');
            }

            $item = $locked->items()->create([
                'media_asset_id' => $data['media_id'] ?? null,
                'label_file_path' => $data['label_file_path'],
                'label_format' => $data['label_format'],
                'species_id' => $data['species_id'] ?? null,
                'annotation_status' => $data['annotation_status'],
                'created_by' => $actor->user_id,
            ]);

            $this->auditLogger->record(
                'training_dataset.item.create', 'training_dataset_items', $item->dataset_item_id,
                $actor->user_id, null, $item->only([
                    'dataset_item_id', 'training_dataset_id', 'media_asset_id', 'label_file_path',
                    'label_format', 'species_id', 'annotation_status', 'created_by',
                ]), $ipAddress, $userAgent, $requestId,
            );

            return $item;
        });
    }
}
