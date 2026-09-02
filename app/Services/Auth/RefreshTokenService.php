<?php

namespace App\Services\Auth;

use App\Models\RefreshToken;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RefreshTokenService
{
    public function __construct(private readonly AuditLogger $auditLogger, private readonly EffectiveAccessService $effectiveAccess) {}

    public function issue(string $userId): string
    {
        $plain = Str::random(80);
        RefreshToken::query()->create([
            'refresh_token_id' => (string) Str::uuid(), 'user_id' => $userId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => Carbon::now('UTC')->addDays((int) config('mangroscan.auth.refresh_token_ttl_days', 30)),
        ]);

        return $plain;
    }

    /** @return array<string, mixed> */
    public function rotate(string $plain, ?string $ip, ?string $agent, ?string $requestId): array
    {
        return DB::transaction(function () use ($plain, $ip, $agent, $requestId): array {
            $token = RefreshToken::query()->with(['user.organization'])->lockForUpdate()->where('token_hash', hash('sha256', $plain))->first();
            if (! $token || $token->revoked_at || $token->expires_at->isPast() || ! $token->user->isActive() || $token->user->organization?->status !== 'active') {
                throw new AuthenticationException('The refresh token is invalid or expired.');
            }
            $newPlain = $this->issue($token->user_id);
            $new = RefreshToken::query()->where('token_hash', hash('sha256', $newPlain))->firstOrFail();
            $token->update(['revoked_at' => Carbon::now('UTC'), 'replaced_by' => $new->refresh_token_id]);
            $expiresAt = Carbon::now('UTC')->addMinutes(max(1, (int) config('mangroscan.auth.access_token_ttl_minutes')));
            $access = $token->user->createToken('MangroScan client', ['*'], $expiresAt);
            $this->auditLogger->record('auth.refresh', 'refresh_tokens', $token->refresh_token_id, $token->user_id, null, ['rotated' => true], $ip, $agent, $requestId);

            return ['user' => ['user_id' => $token->user->user_id, 'organization_id' => $token->user->organization_id, 'first_name' => $token->user->first_name, 'last_name' => $token->user->last_name, 'email' => $token->user->email], 'access_token' => $access->plainTextToken, 'expires_at' => $expiresAt->toIso8601String(), 'refresh_token' => $newPlain, ...$this->effectiveAccess->rolesAndPermissions($token->user)];
        });
    }
}
