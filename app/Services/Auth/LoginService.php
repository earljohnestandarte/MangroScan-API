<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LoginService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly EffectiveAccessService $effectiveAccess,
        private readonly RefreshTokenService $refreshTokens,
    ) {}

    /**
    * @return array{user: array<string, string>, access_token: string, expires_at: string, refresh_token: string, roles: list<string>, permissions: list<string>}|null
     */
    public function attempt(
        string $email,
        string $password,
        ?string $deviceName,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): ?array {
        $user = User::query()
            ->with(['organization', 'roles.permissions'])
            ->where('email', $email)
            ->first();

        if ($user === null) {
            Hash::make($password);
        }

        if ($user === null
            || ! $user->isActive()
            || $user->organization?->status !== 'active'
            || ! Hash::check($password, $user->password)) {
            $this->auditLogger->record(
                action: 'auth.failed',
                tableName: 'users',
                recordId: $user?->user_id,
                userId: null,
                oldValues: null,
                newValues: ['email_hash' => hash('sha256', $email)],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return null;
        }

        return DB::transaction(function () use ($user, $deviceName, $ipAddress, $userAgent, $requestId): array {
            $expiresAt = Carbon::now('UTC')->addMinutes(max(
                1,
                (int) config('mangroscan.auth.access_token_ttl_minutes'),
            ));
            $issuedToken = $user->createToken(
                $deviceName ?: 'MangroScan client',
                ['*'],
                $expiresAt,
            );
            $refreshToken = $this->refreshTokens->issue($user->user_id);

            $this->auditLogger->record(
                action: 'auth.login',
                tableName: 'users',
                recordId: $user->user_id,
                userId: $user->user_id,
                oldValues: null,
                newValues: ['device_name' => $deviceName ?: 'MangroScan client'],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            $access = $this->effectiveAccess->rolesAndPermissions($user);

            return [
                'user' => [
                    'user_id' => $user->user_id,
                    'organization_id' => $user->organization_id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                ],
                'access_token' => $issuedToken->plainTextToken,
                'expires_at' => $expiresAt->toIso8601String(),
                'refresh_token' => $refreshToken,
                ...$access,
            ];
        });
    }
}
