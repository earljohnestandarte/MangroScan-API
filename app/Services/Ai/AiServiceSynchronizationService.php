<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiInferenceClient;
use App\Exceptions\DownstreamServiceException;
use App\Exceptions\WorkflowConflictException;
use App\Models\AiModel;
use App\Models\AiModelVersion;
use App\Models\AiService;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AiServiceSynchronizationService
{
    public function __construct(
        private readonly AiInferenceClient $client,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @return array{models_synced: int, capabilities: array<string, bool|string|int|float|null>} */
    public function synchronize(
        AiService $service,
        User $actor,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): array {
        $this->ensureSynchronizable($service);
        $apiKey = Crypt::decryptString($this->encryptedKey($service->ai_service_id));

        try {
            $metadata = $this->client->models($service->base_url, $apiKey);
        } finally {
            unset($apiKey);
        }

        return DB::transaction(function () use ($service, $actor, $metadata, $ipAddress, $userAgent, $requestId): array {
            $this->lockService($service->ai_service_id);
            $current = AiService::query()
                ->select(['ai_service_id', 'enabled', 'health_status', 'capabilities', 'last_synchronized_at', 'updated_at'])
                ->lockForUpdate()
                ->findOrFail($service->ai_service_id);
            $this->ensureSynchronizable($current);

            $modelKeys = [];
            $versionCount = 0;
            foreach ($metadata['models'] as $incomingModel) {
                $model = AiModel::withTrashed()->firstOrNew([
                    'ai_service_id' => $current->ai_service_id,
                    'external_model_key' => $incomingModel['key'],
                ]);
                if (! $model->exists) {
                    $model->model_id = (string) str()->uuid();
                    $model->created_by = $actor->user_id;
                }
                $model->fill([
                    'model_name' => $incomingModel['name'],
                    'model_type' => $incomingModel['type'],
                    'framework' => $incomingModel['framework'],
                    'description' => $incomingModel['description'],
                ]);
                $model->deleted_at = null;
                $model->save();
                $modelKeys[] = $incomingModel['key'];

                foreach ($incomingModel['versions'] as $incomingVersion) {
                    $version = AiModelVersion::query()->firstOrNew([
                        'model_id' => $model->model_id,
                        'version_label' => $incomingVersion['label'],
                    ]);
                    if (! $version->exists) {
                        $version->model_version_id = (string) str()->uuid();
                    }
                    $version->fill([
                        'model_file_path' => $incomingVersion['artifact_ref'],
                        'accuracy' => $incomingVersion['accuracy'],
                        'precision_score' => $incomingVersion['precision_score'],
                        'recall_score' => $incomingVersion['recall_score'],
                        'f1_score' => $incomingVersion['f1_score'],
                        'rmse' => $incomingVersion['rmse'],
                        'release_notes' => $incomingVersion['release_notes'],
                    ])->save();
                    $versionCount++;
                }
            }

            $old = [
                'capabilities' => $current->capabilities,
                'last_synchronized_at' => $current->last_synchronized_at?->utc()->toIso8601String(),
            ];
            $current->forceFill([
                'capabilities' => $metadata['capabilities'],
                'last_synchronized_at' => now(),
            ])->save();

            $this->auditLogger->record(
                action: 'ai_service.synchronize',
                tableName: 'ai_services',
                recordId: $current->ai_service_id,
                userId: $actor->user_id,
                oldValues: $old,
                newValues: [
                    'models_synced' => count($metadata['models']),
                    'versions_synced' => $versionCount,
                    'model_keys' => $modelKeys,
                    'capability_keys' => array_keys($metadata['capabilities']),
                    'last_synchronized_at' => $current->last_synchronized_at?->utc()->toIso8601String(),
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return [
                'models_synced' => count($metadata['models']),
                'capabilities' => $metadata['capabilities'],
            ];
        });
    }

    private function ensureSynchronizable(AiService $service): void
    {
        if (! $service->enabled || $service->health_status !== 'healthy') {
            throw new WorkflowConflictException(
                'Only enabled, healthy AI services can synchronize models.',
                ['enabled' => $service->enabled, 'health_status' => $service->health_status],
            );
        }
    }

    private function encryptedKey(string $serviceId): string
    {
        if (DB::getDriverName() === 'pgsql') {
            $value = DB::scalar('SELECT app.ai_service_encrypted_key(?)', [$serviceId]);
        } else {
            $value = DB::table('ai_services')->where('ai_service_id', $serviceId)
                ->value('encrypted_api_key');
        }

        if (! is_string($value) || $value === '') {
            throw new DownstreamServiceException(
                'The AI service credential is unavailable.', 503, 'SERVICE_UNAVAILABLE',
            );
        }

        return $value;
    }

    private function lockService(string $serviceId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$serviceId]);
        }
    }
}
