<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    // [AUTH-01] Valid credentials return the exact documented contract.
    public function test_it_authenticates_an_active_user_with_effective_access(): void
    {
        $identity = $this->createIdentityGraph();

        $response = $this
            ->withHeaders([
                'X-Request-ID' => 'req_auth_01_success',
                'User-Agent' => 'MangroScan Expo Test',
            ])
            ->postJson('/api/v1/auth/login', [
                'email' => '  RESEARCHER@EXAMPLE.TEST ',
                'password' => 'correct-password',
                'device_name' => 'Researcher phone',
            ]);

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_auth_01_success')
            ->assertJsonPath('data.user.user_id', $identity['user_id'])
            ->assertJsonPath('data.user.organization_id', $identity['organization_id'])
            ->assertJsonPath('data.roles', ['Researcher'])
            ->assertJsonPath('data.permissions', [
                'media.process',
                'mission.read',
                'validation.create',
            ])
            ->assertJsonPath('meta.request_id', 'req_auth_01_success');

        $payload = $response->json();
        $this->assertSame(['data', 'meta'], array_keys($payload));
        $this->assertSame(
            ['user', 'access_token', 'expires_at', 'roles', 'permissions'],
            array_keys($payload['data']),
        );
        $this->assertSame([
            'user_id',
            'organization_id',
            'first_name',
            'last_name',
            'email',
        ], array_keys($payload['data']['user']));
        $this->assertSame('researcher@example.test', $payload['data']['user']['email']);
        $this->assertTrue(
            Carbon::parse($payload['data']['expires_at'])->isAfter(now()->addMinutes(59)),
        );

        [$tokenId, $plainTextSecret] = explode('|', $payload['data']['access_token'], 2);
        $token = PersonalAccessToken::query()->findOrFail($tokenId);
        $this->assertSame(hash('sha256', $plainTextSecret), $token->token);

        $audit = AuditLog::query()->where('action', 'auth.login')->sole();
        $this->assertSame($identity['user_id'], $audit->user_id);
        $this->assertSame('req_auth_01_success', $audit->request_id);
        $this->assertSame('Researcher phone', $audit->new_values['device_name']);
        $this->assertStringNotContainsString('correct-password', $audit->toJson());
        $this->assertStringNotContainsString($plainTextSecret, $audit->toJson());
    }

    // [AUTH-01] Invalid credentials return 401 and create safe audit evidence.
    public function test_it_rejects_invalid_credentials_without_issuing_a_token(): void
    {
        $this->createIdentityGraph();

        $response = $this
            ->withHeader('X-Request-ID', 'req_auth_01_invalid')
            ->postJson('/api/v1/auth/login', [
                'email' => 'researcher@example.test',
                'password' => 'wrong-password',
            ]);

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'The provided credentials are invalid.',
                    'details' => [],
                    'request_id' => 'req_auth_01_invalid',
                ],
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $audit = AuditLog::query()->where('action', 'auth.failed')->sole();
        $this->assertSame(hash('sha256', 'researcher@example.test'), $audit->new_values['email_hash']);
        $this->assertStringNotContainsString('wrong-password', $audit->toJson());
    }

    // [AUTH-01] Inactive users receive the same non-enumerating 401 response.
    public function test_it_rejects_an_inactive_user(): void
    {
        $identity = $this->createIdentityGraph();
        DB::table('users')->where('user_id', $identity['user_id'])->update(['status' => 'inactive']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'researcher@example.test',
            'password' => 'correct-password',
        ])->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.failed']);
    }

    // [AUTH-01] Users cannot authenticate through an inactive organization.
    public function test_it_rejects_a_user_from_an_inactive_organization(): void
    {
        $identity = $this->createIdentityGraph();
        DB::table('organizations')
            ->where('organization_id', $identity['organization_id'])
            ->update(['status' => 'inactive']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'researcher@example.test',
            'password' => 'correct-password',
        ])->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.failed']);
    }

    // [AUTH-01] Malformed requests use the standard 422 error envelope.
    public function test_it_validates_the_login_request(): void
    {
        $response = $this
            ->withHeader('X-Request-ID', 'req_auth_01_validation')
            ->postJson('/api/v1/auth/login', ['email' => 'not-an-email']);

        $response
            ->assertUnprocessable()
            ->assertHeader('X-Request-ID', 'req_auth_01_validation')
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.request_id', 'req_auth_01_validation')
            ->assertJsonValidationErrors(['email', 'password'], 'error.details');

        $this->assertSame(['error'], array_keys($response->json()));
    }

    // [AUTH-01] Repeated attempts are rate limited with the documented 429.
    public function test_it_rate_limits_repeated_login_attempts(): void
    {
        config(['mangroscan.auth.login_attempts_per_minute' => 2]);

        $payload = [
            'email' => 'missing@example.test',
            'password' => 'wrong-password',
        ];

        $this->postJson('/api/v1/auth/login', $payload)->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', $payload)->assertUnauthorized();

        $this->withHeader('X-Request-ID', 'req_auth_01_throttled')
            ->postJson('/api/v1/auth/login', $payload)
            ->assertTooManyRequests()
            ->assertHeader('X-Request-ID', 'req_auth_01_throttled')
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_auth_01_throttled');
    }

    // [AUTH-01] Token creation rolls back if mandatory audit persistence fails.
    public function test_it_rolls_back_token_creation_when_audit_persistence_fails(): void
    {
        $this->createIdentityGraph();

        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $auditLogger);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'researcher@example.test',
            'password' => 'correct-password',
        ])->assertInternalServerError();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * @return array{organization_id: string, user_id: string}
     */
    private function createIdentityGraph(): array
    {
        $organizationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $roleId = (string) Str::uuid();

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
        DB::table('roles')->insert([
            'role_id' => $roleId,
            'organization_id' => $organizationId,
            'role_name' => 'Researcher',
            'role_code' => 'researcher',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['mission.read', 'media.process', 'validation.create'] as $code) {
            $permissionId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'permission_id' => $permissionId,
                'permission_code' => $code,
                'permission_name' => $code,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('role_permissions')->insert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $otherOrganizationId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $foreignPermissionId = (string) Str::uuid();
        DB::table('organizations')->insert([
            'organization_id' => $otherOrganizationId,
            'organization_name' => 'Other Organization',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('roles')->insert([
            'role_id' => $foreignRoleId,
            'organization_id' => $otherOrganizationId,
            'role_name' => 'Foreign Administrator',
            'role_code' => 'foreign_administrator',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('permissions')->insert([
            'permission_id' => $foreignPermissionId,
            'permission_code' => 'organizations.manage',
            'permission_name' => 'organizations.manage',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_permissions')->insert([
            'role_id' => $foreignRoleId,
            'permission_id' => $foreignPermissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $foreignRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'organization_id' => $organizationId,
            'user_id' => $userId,
        ];
    }
}
