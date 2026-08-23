<?php

namespace App\Services\Ai;

use App\Exceptions\WorkflowConflictException;
use App\Models\AiService;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AiServiceCredentialRotationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function rotate(
        AiService $service,
        User $actor,
        string $apiKey,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): void {
        DB::transaction(function () use ($service, $actor, $apiKey, $ipAddress, $userAgent, $requestId): void {
            $locked = AiService::query()->lockForUpdate()->findOrFail($service->ai_service_id);

            if (hash_equals(Crypt::decryptString($locked->encrypted_api_key), $apiKey)) {
                throw new WorkflowConflictException('The replacement API key must differ from the current credential.');
            }

            $locked->forceFill(['encrypted_api_key' => Crypt::encryptString($apiKey)])->save();

            $this->auditLogger->record(
                action: 'ai_service.credentials.rotate',
                tableName: 'ai_services',
                recordId: $locked->ai_service_id,
                userId: $actor->user_id,
                oldValues: ['credential_configured' => true],
                newValues: ['credential_configured' => true, 'rotated_at' => now('UTC')->toIso8601String()],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );
        });
    }
}
