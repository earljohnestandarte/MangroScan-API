<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiInferenceClient;
use App\Exceptions\DownstreamServiceException;
use App\Exceptions\WorkflowConflictException;
use App\Models\AiService;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AiServiceHealthTestService
{
    public function __construct(
        private readonly AiInferenceClient $client,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @return array{status: string, version: string, latency_ms: int} */
    public function test(
        AiService $service,
        User $actor,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): array {
        if (! $service->enabled) {
            throw new WorkflowConflictException(
                'Disabled AI services cannot be health-tested.',
                ['enabled' => false],
            );
        }

        $apiKey = Crypt::decryptString($this->encryptedKey($service->ai_service_id));

        try {
            $result = $this->client->health($service->base_url, $apiKey);
        } catch (DownstreamServiceException $exception) {
            $this->recordEvidence(
                service: $service,
                actor: $actor,
                status: 'unavailable',
                version: null,
                latencyMs: null,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            throw $exception;
        } finally {
            unset($apiKey);
        }

        $this->recordEvidence(
            service: $service,
            actor: $actor,
            status: $result['status'],
            version: $result['version'],
            latencyMs: $result['latency_ms'],
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            requestId: $requestId,
        );

        return $result;
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
                'The AI service credential is unavailable.',
                503,
                'SERVICE_UNAVAILABLE',
            );
        }

        return $value;
    }

    private function recordEvidence(
        AiService $service,
        User $actor,
        string $status,
        ?string $version,
        ?int $latencyMs,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): void {
        DB::transaction(function () use ($service, $actor, $status, $version, $latencyMs, $ipAddress, $userAgent, $requestId): void {
            $current = AiService::query()
                ->select([
                    'ai_service_id',
                    'health_status',
                    'service_version',
                    'last_health_checked_at',
                    'last_health_latency_ms',
                    'updated_at',
                ])
                ->lockForUpdate()
                ->findOrFail($service->ai_service_id);
            $old = [
                'health_status' => $current->health_status,
                'service_version' => $current->service_version,
                'last_health_checked_at' => $current->last_health_checked_at?->utc()->toIso8601String(),
                'last_health_latency_ms' => $current->last_health_latency_ms,
            ];
            $current->forceFill([
                'health_status' => $status,
                'service_version' => $version,
                'last_health_checked_at' => now(),
                'last_health_latency_ms' => $latencyMs,
            ])->save();

            $this->auditLogger->record(
                action: 'ai_service.health_test',
                tableName: 'ai_services',
                recordId: $current->ai_service_id,
                userId: $actor->user_id,
                oldValues: $old,
                newValues: [
                    'health_status' => $current->health_status,
                    'service_version' => $current->service_version,
                    'last_health_checked_at' => $current->last_health_checked_at?->utc()->toIso8601String(),
                    'last_health_latency_ms' => $current->last_health_latency_ms,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );
        });
    }
}
