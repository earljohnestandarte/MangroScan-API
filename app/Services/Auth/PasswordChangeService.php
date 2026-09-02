<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordChangeService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function change(User $actor, string $currentPassword, string $newPassword, ?string $ipAddress, ?string $userAgent, ?string $requestId): void
    {
        if (! Hash::check($currentPassword, $actor->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        DB::transaction(function () use ($actor, $newPassword, $ipAddress, $userAgent, $requestId): void {
            $actor->forceFill(['password' => Hash::make($newPassword)])->save();
            $revokedTokens = $actor->tokens()->count();
            $actor->tokens()->delete();
            DB::table('refresh_tokens')->where('user_id', $actor->user_id)->delete();

            $this->auditLogger->record(
                action: 'auth.password.changed',
                tableName: 'users',
                recordId: $actor->user_id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: ['credentials_revoked' => $revokedTokens],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );
        });
    }
}
