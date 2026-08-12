<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class EffectivePermissionsTest extends TestCase
{
    use RefreshDatabase;

    // [AUTH-08] The lightweight refresh returns sorted global and tenant-owned access only.
    public function test_it_returns_tenant_scoped_effective_access(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_auth_08_success')
            ->getJson('/api/v1/auth/permissions')
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_auth_08_success')
            ->assertExactJson([
                'data' => [
                    'roles' => ['Global Viewer', 'Researcher'],
                    'permissions' => ['media.process', 'mission.read', 'validation.create'],
                ],
                'meta' => [
                    'request_id' => 'req_auth_08_success',
                ],
            ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [AUTH-08] Permission refresh requires a valid Bearer token.
    public function test_it_rejects_an_unauthenticated_request(): void
    {
        $this->withHeader('X-Request-ID', 'req_auth_08_missing')
            ->getJson('/api/v1/auth/permissions')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.request_id', 'req_auth_08_missing');
    }

    // [AUTH-08] Suspended identities cannot refresh authorization data.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $identity = $this->createIdentityGraph();
        DB::table('organizations')
            ->where('organization_id', $identity['organization_id'])
            ->update(['status' => 'inactive']);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/auth/permissions')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [AUTH-08] Lightweight refreshes consume the shared authenticated request budget.
    public function test_it_rate_limits_permission_refreshes(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->getJson('/api/v1/auth/permissions')
            ->assertOk();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_auth_08_throttled')
            ->getJson('/api/v1/auth/permissions')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_auth_08_throttled');
    }

    /**
     * @return array{organization_id: string, token: string}
     */
    private function createIdentityGraph(): array
    {
        $organizationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $tenantRoleId = (string) Str::uuid();
        $globalRoleId = (string) Str::uuid();

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
            [
                'role_id' => $tenantRoleId,
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
                'role_id' => $tenantRoleId,
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

        $this->assignPermission($tenantRoleId, 'media.process');
        $this->assignPermission($tenantRoleId, 'validation.create');
        $this->assignPermission($globalRoleId, 'mission.read');
        $this->assignForeignAccess($userId);

        return [
            'organization_id' => $organizationId,
            'token' => User::query()
                ->findOrFail($userId)
                ->createToken('Permission refresh test', ['*'], now()->addHour())
                ->plainTextToken,
        ];
    }

    private function assignPermission(string $roleId, string $code): void
    {
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

    private function assignForeignAccess(string $userId): void
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
        $this->assignPermission($roleId, 'organizations.manage');
    }
}
