<?php

namespace App\Services\Ai;

use App\Exceptions\WorkflowConflictException;
use App\Models\AiModel;
use App\Models\AiModelVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class AiModelVersionDeploymentService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function deploy(
        AiModel $model,
        AiModelVersion $version,
        User $actor,
        ?string $releaseNotes,
        bool $releaseNotesProvided,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): AiModelVersion {
        return DB::transaction(function () use ($model, $version, $actor, $releaseNotes, $releaseNotesProvided, $ipAddress, $userAgent, $requestId): AiModelVersion {
            $locked = AiModelVersion::query()
                ->where('model_id', $model->model_id)
                ->lockForUpdate()
                ->findOrFail($version->model_version_id);

            if (collect(['accuracy', 'precision_score', 'recall_score', 'f1_score', 'rmse'])
                ->every(fn (string $field): bool => $locked->{$field} === null)) {
                throw new WorkflowConflictException('The model version requires validation metrics before deployment.');
            }

            $previouslyDeployed = AiModelVersion::query()
                ->where('model_id', $model->model_id)
                ->where('is_deployed', true)
                ->pluck('model_version_id')
                ->all();

            AiModelVersion::query()
                ->where('model_id', $model->model_id)
                ->where('model_version_id', '!=', $locked->model_version_id)
                ->where('is_deployed', true)
                ->update(['is_deployed' => false, 'updated_at' => now('UTC')]);

            $changes = ['is_deployed' => true];
            if ($releaseNotesProvided) {
                $changes['release_notes'] = $releaseNotes;
            }
            $locked->forceFill($changes)->save();

            $this->auditLogger->record(
                action: 'ai_model_version.deploy',
                tableName: 'ai_model_versions',
                recordId: $locked->model_version_id,
                userId: $actor->user_id,
                oldValues: ['deployed_version_ids' => $previouslyDeployed],
                newValues: [
                    'model_id' => $model->model_id,
                    'deployed_version_id' => $locked->model_version_id,
                    'release_notes' => $locked->release_notes,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $locked->refresh();
        });
    }
}
