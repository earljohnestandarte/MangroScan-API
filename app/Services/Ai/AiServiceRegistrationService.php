<?php

namespace App\Services\Ai;

use App\Exceptions\WorkflowConflictException;
use App\Models\AiService;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiServiceRegistrationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function register(
        User $actor,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): AiService {
        return DB::transaction(function () use ($actor, $data, $ipAddress, $userAgent, $requestId): AiService {
            $normalizedName = Str::lower($data['service_name']);
            $normalizedUrl = Str::lower($data['base_url']);
            $environment = Str::lower($data['environment']);
            $this->lockIdentity($normalizedName, $environment, $normalizedUrl);

            if (AiService::query()->whereRaw('LOWER(base_url) = ?', [$normalizedUrl])->exists()) {
                throw new WorkflowConflictException(
                    'An AI service with this base URL already exists.',
                    ['base_url' => $data['base_url']],
                );
            }

            if (AiService::query()
                ->whereRaw('LOWER(service_name) = ?', [$normalizedName])
                ->whereRaw('LOWER(environment) = ?', [$environment])
                ->exists()) {
                throw new WorkflowConflictException(
                    'An AI service with this name already exists in the environment.',
                    ['service_name' => $data['service_name'], 'environment' => $environment],
                );
            }

            $service = AiService::query()->create([
                'service_name' => $data['service_name'],
                'base_url' => $data['base_url'],
                'encrypted_api_key' => Crypt::encryptString($data['api_key']),
                'environment' => $environment,
                'enabled' => $data['enabled'],
                'health_status' => 'unknown',
                'created_by' => $actor->user_id,
            ]);

            $this->auditLogger->record(
                action: 'ai_service.create',
                tableName: 'ai_services',
                recordId: $service->ai_service_id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: [
                    'ai_service_id' => $service->ai_service_id,
                    'service_name' => $service->service_name,
                    'base_url' => $service->base_url,
                    'environment' => $service->environment,
                    'enabled' => $service->enabled,
                    'health_status' => $service->health_status,
                    'created_by' => $service->created_by,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $service;
        });
    }

    private function lockIdentity(string $name, string $environment, string $url): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([$url, $name.'|'.$environment] as $identity) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$identity]);
        }
    }
}
