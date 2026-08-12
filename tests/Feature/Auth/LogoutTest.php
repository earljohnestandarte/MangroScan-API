<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    // [AUTH-03] Logout revokes only the presented token and writes safe audit evidence.
    public function test_it_revokes_only_the_current_token(): void
    {
        $identity = $this->createIdentityWithTwoTokens();

        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer '.$identity['current_token'],
                'X-Request-ID' => 'req_auth_03_logout',
                'User-Agent' => 'MangroScan Logout Test',
            ])
            ->postJson('/api/v1/auth/logout');

        $response
            ->assertNoContent()
            ->assertHeader('X-Request-ID', 'req_auth_03_logout');
        $this->assertSame('', $response->getContent());

        $this->assertNull(PersonalAccessToken::query()->find($identity['current_token_id']));
        $this->assertNotNull(PersonalAccessToken::query()->find($identity['other_token_id']));

        $audit = AuditLog::query()->where('action', 'auth.logout')->sole();
        $this->assertSame('personal_access_tokens', $audit->table_name);
        $this->assertSame($identity['current_token_id'], $audit->record_id);
        $this->assertSame($identity['user_id'], $audit->user_id);
        $this->assertSame('Current phone', $audit->new_values['device_name']);
        $this->assertNotEmpty($audit->new_values['revoked_at']);
        $this->assertSame('req_auth_03_logout', $audit->request_id);
        $this->assertStringNotContainsString($identity['current_token'], $audit->toJson());

        Auth::forgetGuards();
        $this->withToken($identity['current_token'])
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($identity['other_token'])
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    // [AUTH-03] Missing or invalid token material uses the shared 401 contract.
    public function test_it_requires_a_valid_bearer_token(): void
    {
        $this->withHeader('X-Request-ID', 'req_auth_03_missing')
            ->postJson('/api/v1/auth/logout')
            ->assertUnauthorized()
            ->assertExactJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication is required.',
                    'details' => [],
                    'request_id' => 'req_auth_03_missing',
                ],
            ]);

        $this->withToken('invalid-token')
            ->postJson('/api/v1/auth/logout')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    // [AUTH-03] Suspended users and organizations may still reduce risk by revoking their own token.
    public function test_it_allows_an_inactive_identity_to_logout(): void
    {
        $identity = $this->createIdentityWithTwoTokens();
        DB::table('users')->where('user_id', $identity['user_id'])->update(['status' => 'inactive']);
        DB::table('organizations')
            ->where('organization_id', $identity['organization_id'])
            ->update(['status' => 'inactive']);

        $this->withToken($identity['current_token'])
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertNull(PersonalAccessToken::query()->find($identity['current_token_id']));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout']);
    }

    // [AUTH-03] Revocation rolls back if mandatory audit persistence fails.
    public function test_it_rolls_back_revocation_when_audit_persistence_fails(): void
    {
        $identity = $this->createIdentityWithTwoTokens();

        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $auditLogger);

        $this->withToken($identity['current_token'])
            ->postJson('/api/v1/auth/logout')
            ->assertInternalServerError();

        $this->assertNotNull(PersonalAccessToken::query()->find($identity['current_token_id']));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [AUTH-03] Authenticated logout attempts share the standard per-user rate limit.
    public function test_it_rate_limits_logout_requests_without_revoking_the_next_token(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentityWithTwoTokens();

        $this->withToken($identity['current_token'])
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->withToken($identity['other_token'])
            ->withHeader('X-Request-ID', 'req_auth_03_throttled')
            ->postJson('/api/v1/auth/logout')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_auth_03_throttled');

        $this->assertNotNull(PersonalAccessToken::query()->find($identity['other_token_id']));
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /**
     * @return array{
     *     organization_id: string,
     *     user_id: string,
     *     current_token_id: string,
     *     current_token: string,
     *     other_token_id: string,
     *     other_token: string
     * }
     */
    private function createIdentityWithTwoTokens(): array
    {
        $organizationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('organizations')->insert([
            'organization_id' => $organizationId,
            'organization_name' => 'MangroScan Research',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'first_name' => 'Researcher',
            'last_name' => 'User',
            'email' => 'researcher@example.test',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->findOrFail($userId);
        $currentToken = $user->createToken('Current phone', ['*'], now()->addHour());
        $otherToken = $user->createToken('Web browser', ['*'], now()->addHour());

        return [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'current_token_id' => (string) $currentToken->accessToken->getKey(),
            'current_token' => $currentToken->plainTextToken,
            'other_token_id' => (string) $otherToken->accessToken->getKey(),
            'other_token' => $otherToken->plainTextToken,
        ];
    }
}
