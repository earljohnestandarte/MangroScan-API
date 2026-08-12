<?php

namespace Tests\Feature\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticatedProfileTest extends TestCase
{
    use RefreshDatabase;

    // [AUTH-02] A valid Bearer token returns only the documented safe profile and tenant-scoped access.
    public function test_it_returns_the_authenticated_profile_and_effective_access(): void
    {
        $identity = $this->createIdentityGraph();

        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer '.$identity['token'],
                'X-Request-ID' => 'req_auth_02_success',
            ])
            ->getJson('/api/v1/auth/me');

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_auth_02_success')
            ->assertExactJson([
                'data' => [
                    'user' => [
                        'user_id' => $identity['user_id'],
                        'organization_id' => $identity['organization_id'],
                        'first_name' => 'Researcher',
                        'middle_name' => null,
                        'last_name' => 'User',
                        'email' => 'researcher@example.test',
                        'status' => 'active',
                    ],
                    'organization' => [
                        'organization_id' => $identity['organization_id'],
                        'organization_name' => 'MangroScan Research',
                        'organization_type' => 'academic',
                        'contact_email' => 'contact@example.test',
                        'contact_number' => '+63 900 000 0000',
                        'address' => 'Davao Region',
                        'status' => 'active',
                    ],
                    'roles' => ['Global Viewer', 'Researcher'],
                    'permissions' => ['media.process', 'mission.read', 'validation.create'],
                ],
                'meta' => [
                    'request_id' => 'req_auth_02_success',
                ],
            ]);

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertNotNull(
            PersonalAccessToken::query()->findOrFail($identity['token_id'])->last_used_at,
        );
    }

    // [AUTH-02] Missing credentials use the standard non-leaking 401 envelope.
    public function test_it_rejects_a_missing_bearer_token(): void
    {
        $this->withHeader('X-Request-ID', 'req_auth_02_missing')
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertHeader('X-Request-ID', 'req_auth_02_missing')
            ->assertExactJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication is required.',
                    'details' => [],
                    'request_id' => 'req_auth_02_missing',
                ],
            ]);
    }

    // [AUTH-02] Invalid and expired opaque token material cannot authenticate.
    public function test_it_rejects_invalid_and_expired_tokens(): void
    {
        $this->withToken('not-a-valid-token')
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $identity = $this->createIdentityGraph(expiresAt: now()->subMinute());

        $this->withToken($identity['token'])
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    // [AUTH-02] Soft-deleted token owners are treated as unauthenticated.
    public function test_it_rejects_an_orphaned_token(): void
    {
        $identity = $this->createIdentityGraph();
        User::query()->findOrFail($identity['user_id'])->delete();

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    // [AUTH-02] A valid token cannot bypass user activation state.
    public function test_it_rejects_an_inactive_user(): void
    {
        $identity = $this->createIdentityGraph();
        DB::table('users')->where('user_id', $identity['user_id'])->update(['status' => 'inactive']);

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_auth_02_inactive_user')
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertExactJson([
                'error' => [
                    'code' => 'ACCOUNT_INACTIVE',
                    'message' => 'The authenticated account is not active.',
                    'details' => [],
                    'request_id' => 'req_auth_02_inactive_user',
                ],
            ]);
    }

    // [AUTH-02] Organization suspension blocks every otherwise-valid member token.
    public function test_it_rejects_a_user_from_an_inactive_organization(): void
    {
        $identity = $this->createIdentityGraph();
        DB::table('organizations')
            ->where('organization_id', $identity['organization_id'])
            ->update(['status' => 'inactive']);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [AUTH-02] Authenticated reads use the standard 429 envelope when their budget is exhausted.
    public function test_it_rate_limits_authenticated_profile_requests(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])->getJson('/api/v1/auth/me')->assertOk();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_auth_02_throttled')
            ->getJson('/api/v1/auth/me')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_auth_02_throttled');
    }

    /**
     * @return array{organization_id: string, user_id: string, token_id: string, token: string}
     */
    private function createIdentityGraph(?DateTimeInterface $expiresAt = null): array
    {
        $organizationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $roleId = (string) Str::uuid();
        $globalRoleId = (string) Str::uuid();

        DB::table('organizations')->insert([
            'organization_id' => $organizationId,
            'organization_name' => 'MangroScan Research',
            'organization_type' => 'academic',
            'contact_email' => 'contact@example.test',
            'contact_number' => '+63 900 000 0000',
            'address' => 'Davao Region',
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
            [
                'role_id' => $roleId,
                'organization_id' => $organizationId,
                'role_name' => 'Researcher',
                'role_code' => 'researcher',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'role_id' => $globalRoleId,
                'organization_id' => null,
                'role_name' => 'Global Viewer',
                'role_code' => 'global_viewer',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('user_roles')->insert([
            [
                'user_id' => $userId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $userId,
                'role_id' => $globalRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assignPermissions($roleId, ['media.process', 'validation.create']);
        $this->assignPermissions($globalRoleId, ['mission.read']);
        $this->assignForeignRole($userId);

        $issuedToken = User::query()->findOrFail($userId)->createToken(
            'Authenticated profile test',
            ['*'],
            $expiresAt,
        );

        return [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'token_id' => $issuedToken->accessToken->getKey(),
            'token' => $issuedToken->plainTextToken,
        ];
    }

    /**
     * @param  list<string>  $codes
     */
    private function assignPermissions(string $roleId, array $codes): void
    {
        foreach ($codes as $code) {
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
    }

    private function assignForeignRole(string $userId): void
    {
        $organizationId = (string) Str::uuid();
        $roleId = (string) Str::uuid();

        DB::table('organizations')->insert([
            'organization_id' => $organizationId,
            'organization_name' => 'Foreign Organization',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('roles')->insert([
            'role_id' => $roleId,
            'organization_id' => $organizationId,
            'role_name' => 'Foreign Administrator',
            'role_code' => 'foreign_administrator',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assignPermissions($roleId, ['organizations.manage']);
    }
}
