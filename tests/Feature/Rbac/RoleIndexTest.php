<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleIndexTest extends TestCase
{
    use RefreshDatabase;

    // [RBAC-01] Authorized callers receive global and current-tenant roles only.
    public function test_it_lists_global_and_current_organization_roles(): void
    {
        $identity = $this->createIdentityGraph(grantLocalPermission: true);

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_rbac_01_success')
            ->getJson('/api/v1/roles')
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_rbac_01_success')
            ->assertExactJson([
                'data' => [
                    [
                        'role_id' => $identity['global_role_id'],
                        'organization_id' => null,
                        'role_name' => 'Global Viewer',
                        'role_code' => 'global_viewer',
                        'description' => 'Cross-tenant reference role',
                        'is_system_role' => true,
                    ],
                    [
                        'role_id' => $identity['researcher_role_id'],
                        'organization_id' => $identity['organization_id'],
                        'role_name' => 'Researcher',
                        'role_code' => 'researcher',
                        'description' => null,
                        'is_system_role' => false,
                    ],
                    [
                        'role_id' => $identity['admin_role_id'],
                        'organization_id' => $identity['organization_id'],
                        'role_name' => 'Tenant Administrator',
                        'role_code' => 'tenant_administrator',
                        'description' => 'Tenant RBAC administrator',
                        'is_system_role' => false,
                    ],
                ],
                'meta' => [
                    'request_id' => 'req_rbac_01_success',
                ],
            ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RBAC-01] Missing authentication uses the shared 401 envelope.
    public function test_it_requires_authentication(): void
    {
        $this->withHeader('X-Request-ID', 'req_rbac_01_missing')
            ->getJson('/api/v1/roles')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.request_id', 'req_rbac_01_missing');
    }

    // [RBAC-01] A valid token without roles.manage receives a standard 403.
    public function test_it_requires_roles_manage_permission(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_rbac_01_forbidden')
            ->getJson('/api/v1/roles')
            ->assertForbidden()
            ->assertExactJson([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to perform this action.',
                    'details' => [
                        'required_permission' => 'roles.manage',
                    ],
                    'request_id' => 'req_rbac_01_forbidden',
                ],
            ]);
    }

    // [RBAC-01] A permission inherited only through a foreign-tenant role cannot authorize access.
    public function test_foreign_organization_permission_does_not_authorize_the_request(): void
    {
        $identity = $this->createIdentityGraph(grantForeignPermission: true);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/roles')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // [RBAC-01] Inactive organizations are rejected before catalog authorization.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $identity = $this->createIdentityGraph(grantLocalPermission: true);
        DB::table('organizations')
            ->where('organization_id', $identity['organization_id'])
            ->update(['status' => 'inactive']);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/roles')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [RBAC-01] Catalog reads consume the shared authenticated request budget.
    public function test_it_rate_limits_role_catalog_requests(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentityGraph(grantLocalPermission: true);

        $this->withToken($identity['token'])->getJson('/api/v1/roles')->assertOk();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_rbac_01_throttled')
            ->getJson('/api/v1/roles')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_rbac_01_throttled');
    }

    /**
     * @return array{
     *     organization_id: string,
     *     admin_role_id: string,
     *     researcher_role_id: string,
     *     global_role_id: string,
     *     token: string
     * }
     */
    private function createIdentityGraph(
        bool $grantLocalPermission = false,
        bool $grantForeignPermission = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $adminRoleId = (string) Str::uuid();
        $researcherRoleId = (string) Str::uuid();
        $globalRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();

        DB::table('organizations')->insert([
            [
                'organization_id' => $organizationId,
                'organization_name' => 'MangroScan Research',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => $foreignOrganizationId,
                'organization_name' => 'Foreign Organization',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('users')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'first_name' => 'RBAC',
            'last_name' => 'Administrator',
            'email' => 'rbac@example.test',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('roles')->insert([
            [
                'role_id' => $globalRoleId,
                'organization_id' => null,
                'role_name' => 'Global Viewer',
                'role_code' => 'global_viewer',
                'description' => 'Cross-tenant reference role',
                'is_system_role' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'role_id' => $researcherRoleId,
                'organization_id' => $organizationId,
                'role_name' => 'Researcher',
                'role_code' => 'researcher',
                'description' => null,
                'is_system_role' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'role_id' => $adminRoleId,
                'organization_id' => $organizationId,
                'role_name' => 'Tenant Administrator',
                'role_code' => 'tenant_administrator',
                'description' => 'Tenant RBAC administrator',
                'is_system_role' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'role_id' => $foreignRoleId,
                'organization_id' => $foreignOrganizationId,
                'role_name' => 'Foreign Administrator',
                'role_code' => 'foreign_administrator',
                'description' => null,
                'is_system_role' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $grantForeignPermission ? $foreignRoleId : $adminRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($grantLocalPermission || $grantForeignPermission) {
            $permissionId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'permission_id' => $permissionId,
                'permission_code' => 'roles.manage',
                'permission_name' => 'Manage roles',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('role_permissions')->insert([
                'role_id' => $grantForeignPermission ? $foreignRoleId : $adminRoleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'organization_id' => $organizationId,
            'admin_role_id' => $adminRoleId,
            'researcher_role_id' => $researcherRoleId,
            'global_role_id' => $globalRoleId,
            'token' => User::query()
                ->findOrFail($userId)
                ->createToken('Role catalog test', ['*'], now()->addHour())
                ->plainTextToken,
        ];
    }
}
