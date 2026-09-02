<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RolePermissionShowTest extends TestCase
{
    use RefreshDatabase;

    // [RBAC-05] Administrators receive the role's exact, sorted permission set.
    public function test_it_returns_the_current_role_permissions(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->withHeader('X-Request-ID', 'req_rbac_05_success')
            ->getJson('/api/v1/roles/'.$g['target_role'].'/permissions')
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_rbac_05_success')
            ->assertExactJson([
                'data' => [
                    'role_id' => $g['target_role'],
                    'permissions' => [
                        [
                            'permission_id' => $g['permissions']['sites.read'],
                            'permission_code' => 'sites.read',
                            'permission_name' => 'View sites',
                            'description' => 'Read survey-site metadata.',
                        ],
                        [
                            'permission_id' => $g['permissions']['users.manage'],
                            'permission_code' => 'users.manage',
                            'permission_name' => 'Manage users',
                            'description' => null,
                        ],
                    ],
                ],
                'meta' => ['request_id' => 'req_rbac_05_success'],
            ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RBAC-05] A role with no assignments returns an authoritative empty set.
    public function test_it_returns_an_empty_permission_set(): void
    {
        $g = $this->graph();

        DB::table('role_permissions')->where('role_id', $g['target_role'])->delete();

        $this->withToken($g['token'])
            ->getJson('/api/v1/roles/'.$g['target_role'].'/permissions')
            ->assertOk()
            ->assertJsonPath('data.role_id', $g['target_role'])
            ->assertJsonCount(0, 'data.permissions');
    }

    // [RBAC-05] Foreign and unelevated global roles remain non-enumerable.
    public function test_it_hides_out_of_scope_roles_without_elevation(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])->getJson('/api/v1/roles/'.$g['foreign_role'].'/permissions')->assertNotFound();
        $this->withToken($g['token'])->getJson('/api/v1/roles/'.$g['global_role'].'/permissions')->assertNotFound();
        $this->withToken($g['token'])->getJson('/api/v1/roles/'.Str::uuid().'/permissions')->assertNotFound();
        $this->withToken($g['token'])->getJson('/api/v1/roles/not-a-uuid/permissions')->assertNotFound();
    }

    // [RBAC-05] Organization elevation permits global roles, never foreign tenant roles.
    public function test_it_allows_global_role_reads_with_elevation(): void
    {
        $g = $this->graph(grantOrganizationPermission: true);

        $this->withToken($g['token'])
            ->getJson('/api/v1/roles/'.$g['global_role'].'/permissions')
            ->assertOk()
            ->assertJsonPath('data.role_id', $g['global_role']);
        $this->withToken($g['token'])->getJson('/api/v1/roles/'.$g['foreign_role'].'/permissions')->assertNotFound();
    }

    // [RBAC-05] Authentication is mandatory.
    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/roles/'.Str::uuid().'/permissions')->assertUnauthorized();
    }

    // [RBAC-05] roles.manage is mandatory.
    public function test_it_requires_roles_manage(): void
    {
        $withoutRoles = $this->graph(grantRolesPermission: false);
        $this->withToken($withoutRoles['token'])
            ->getJson('/api/v1/roles/'.$withoutRoles['target_role'].'/permissions')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'roles.manage');
    }

    // [RBAC-05] permissions.manage is independently mandatory.
    public function test_it_requires_permissions_manage(): void
    {
        $withoutPermissions = $this->graph(grantPermissionsPermission: false);
        $this->withToken($withoutPermissions['token'])
            ->getJson('/api/v1/roles/'.$withoutPermissions['target_role'].'/permissions')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'permissions.manage');
    }

    // [RBAC-05] Permissions inherited solely from a foreign role cannot authorize the read.
    public function test_it_rejects_foreign_tenant_authority(): void
    {
        $g = $this->graph(foreignAuthority: true);

        $this->withToken($g['token'])
            ->getJson('/api/v1/roles/'.$g['target_role'].'/permissions')
            ->assertForbidden();
    }

    // [RBAC-05] Reads consume the shared authenticated throttle budget.
    public function test_it_rate_limits_role_permission_reads(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $uri = '/api/v1/roles/'.$g['target_role'].'/permissions';

        $this->withToken($g['token'])->getJson($uri)->assertOk();
        $this->withToken($g['token'])->getJson($uri)->assertTooManyRequests();
    }

    /** @return array<string, mixed> */
    private function graph(
        bool $grantRolesPermission = true,
        bool $grantPermissionsPermission = true,
        bool $grantOrganizationPermission = false,
        bool $foreignAuthority = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $adminRoleId = (string) Str::uuid();
        $targetRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $foreignAdminRoleId = (string) Str::uuid();
        $globalRoleId = (string) Str::uuid();

        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => 'RBAC Five Organization', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => 'Foreign RBAC Five Organization', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('users')->insert([
            'user_id' => $actorId,
            'organization_id' => $organizationId,
            'first_name' => 'Role',
            'last_name' => 'Reader',
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([
            [$adminRoleId, $organizationId, 'RBAC Administrator', 'rbac_administrator', false],
            [$targetRoleId, $organizationId, 'Researcher', 'researcher', false],
            [$foreignRoleId, $foreignOrganizationId, 'Foreign Researcher', 'foreign_researcher', false],
            [$foreignAdminRoleId, $foreignOrganizationId, 'Foreign Administrator', 'foreign_administrator', false],
            [$globalRoleId, null, 'Global Viewer', 'global_viewer', true],
        ] as [$id, $organization, $name, $code, $isSystem]) {
            DB::table('roles')->insert([
                'role_id' => $id,
                'organization_id' => $organization,
                'role_name' => $name,
                'role_code' => $code,
                'is_system_role' => $isSystem,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionDefinitions = [
            'roles.manage' => ['Manage roles', null],
            'permissions.manage' => ['Manage permissions', null],
            'organizations.manage' => ['Manage organizations', null],
            'sites.read' => ['View sites', 'Read survey-site metadata.'],
            'users.manage' => ['Manage users', null],
        ];
        $permissions = [];
        foreach ($permissionDefinitions as $code => [$name, $description]) {
            $permissions[$code] = (string) Str::uuid();
            DB::table('permissions')->insert([
                'permission_id' => $permissions[$code],
                'permission_code' => $code,
                'permission_name' => $name,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (array_filter([
            $grantRolesPermission ? 'roles.manage' : null,
            $grantPermissionsPermission ? 'permissions.manage' : null,
            $grantOrganizationPermission ? 'organizations.manage' : null,
        ]) as $code) {
            DB::table('role_permissions')->insert([
                'role_id' => $foreignAuthority ? $foreignAdminRoleId : $adminRoleId,
                'permission_id' => $permissions[$code],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach (['users.manage', 'sites.read'] as $code) {
            DB::table('role_permissions')->insert([
                'role_id' => $targetRoleId,
                'permission_id' => $permissions[$code],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('user_roles')->insert([
            'user_id' => $actorId,
            'role_id' => $foreignAuthority ? $foreignAdminRoleId : $adminRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'target_role' => $targetRoleId,
            'foreign_role' => $foreignRoleId,
            'global_role' => $globalRoleId,
            'permissions' => $permissions,
            'token' => User::query()->findOrFail($actorId)->createToken('rbac-five')->plainTextToken,
        ];
    }
}
