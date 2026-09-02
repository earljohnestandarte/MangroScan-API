<?php

namespace App\Services\Training;

use App\Exceptions\WorkflowConflictException;
use App\Models\TrainingDataset;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TrainingDatasetCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data, ?string $ipAddress, ?string $userAgent, ?string $requestId): TrainingDataset
    {
        return DB::transaction(function () use ($actor, $data, $ipAddress, $userAgent, $requestId): TrainingDataset {
            $name = Str::lower($data['dataset_name']);
            $version = isset($data['version_label']) ? Str::lower((string) $data['version_label']) : '';
            $this->lockIdentity($name.'|'.$version);

            $duplicate = TrainingDataset::query()->whereRaw('LOWER(dataset_name) = ?', [$name])
                ->where(function ($query) use ($version): void {
                    if ($version === '') {
                        $query->whereNull('version_label')->orWhere('version_label', '');
                    } else {
                        $query->whereRaw('LOWER(version_label) = ?', [$version]);
                    }
                })->exists();
            if ($duplicate) {
                throw new WorkflowConflictException('A training dataset with this name and version already exists.');
            }

            $dataset = TrainingDataset::query()->create([
                ...$data,
                'created_by' => $actor->user_id,
            ]);

            $this->auditLogger->record(
                'training_dataset.create', 'training_datasets', $dataset->training_dataset_id,
                $actor->user_id, null, $dataset->only([
                    'training_dataset_id', 'dataset_name', 'dataset_type', 'source',
                    'description', 'version_label', 'created_by',
                ]), $ipAddress, $userAgent, $requestId,
            );

            return $dataset;
        });
    }

    private function lockIdentity(string $identity): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$identity]);
        }
    }
}
