<?php

namespace App\Services\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class LogoutService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function revoke(
        User $user,
        PersonalAccessToken $token,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): void {
        DB::transaction(function () use ($user, $token, $ipAddress, $userAgent, $requestId): void {
            $tokenId = $token->getKey();
            $deviceName = $token->name;

            $token->delete();

            $this->auditLogger->record(
                action: 'auth.logout',
                tableName: 'personal_access_tokens',
                recordId: $tokenId,
                userId: $user->user_id,
                oldValues: null,
                newValues: [
                    'device_name' => $deviceName,
                    'revoked_at' => now('UTC')->toIso8601String(),
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );
        });
    }
}
