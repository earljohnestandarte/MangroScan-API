<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PasswordResetService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function reset(string $email, string $token, string $password, ?string $ipAddress, ?string $userAgent, ?string $requestId): void
    {
        $user = User::query()->with('organization')->where('email', $email)->first();
        if ($user === null || ! $user->isActive() || $user->organization?->status !== 'active') {
            throw new NotFoundHttpException('No active account was found for this email address.');
        }

        $repository = Password::broker()->getRepository();
        DB::transaction(function () use ($repository, $user, $token, $password, $ipAddress, $userAgent, $requestId): void {
            DB::table('password_reset_tokens')->where('email', $user->email)->lockForUpdate()->first();
            if (! $repository->exists($user, $token)) {
                throw new BadRequestHttpException('The password reset token is invalid or expired.');
            }

            $user->forceFill(['password' => Hash::make($password)])->save();
            $repository->delete($user);
            $revokedCredentials = $user->tokens()->count();
            $user->tokens()->delete();
            $this->auditLogger->record(
                action: 'auth.password.reset.completed', tableName: 'users', recordId: $user->user_id,
                userId: $user->user_id, oldValues: null,
                newValues: ['credentials_revoked' => $revokedCredentials],
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );
        });
    }
}
