<?php

namespace Tests\Feature\Rbac;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RolePermissionReplaceTest extends TestCase
{
    use RefreshDatabase;

    // [RBAC-04] Administrators replace a tenant role's full permission set with audit evidence.
    public function test_it_replaces_role_permissions(): void
    {
        $g = $this->graph();
        $response = $this->withToken($g['token'])->withHeader('X-Request-ID', 'req_rbac_04')
            ->putJson('/api/v1/roles/'.$g['target_role'].'/permissions', [
                'permission_ids' => [$g['permissions']['users.manage'], $g['permissions']['sites.read']],
            ]);

        $response->assertOk()->assertJsonPath('data.role_id', $g['target_role'])
            ->assertJsonPath('data.permissions.0.permission_code', 'sites.read')
            ->assertJsonPath('data.permissions.1.permission_code', 'users.manage')
            ->assertJsonPath('meta.request_id', 'req_rbac_04')->assertJsonCount(2, 'data.permissions');
        $this->assertEqualsCanonicalizing([
            $g['permissions']['sites.read'], $g['permissions']['users.manage'],
        ], DB::table('role_permissions')->where('role_id', $g['target_role'])->pluck('permission_id')->all());
        $audit = AuditLog::query()->sole();
        $this->assertSame('role.permissions.replace', $audit->action);
        $this->assertSame($g['target_role'], $audit->record_id);
        $this->assertSame([$g['permissions']['users.manage']], $audit->old_values['permission_ids']);
        $this->assertSame(2, count($audit->new_values['permission_ids']));
    }

    // [RBAC-04] An empty array intentionally removes every permission.
    public function test_it_accepts_an_empty_permission_set(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->putJson('/api/v1/roles/'.$g['target_role'].'/permissions', ['permission_ids' => []])
            ->assertOk()->assertJsonCount(0, 'data.permissions');
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $g['target_role']]);
    }

    // [RBAC-04] Missing, malformed, duplicate and unknown permission identifiers fail validation.
    public function test_it_validates_permission_sets(): void
    {
        $g = $this->graph();
        $uri = '/api/v1/roles/'.$g['target_role'].'/permissions';
        $this->withToken($g['token'])->putJson($uri, [])->assertUnprocessable()
            ->assertJsonValidationErrors(['permission_ids'], 'error.details');
        $id = $g['permissions']['sites.read'];
        $this->withToken($g['token'])->putJson($uri, ['permission_ids' => [$id, $id, 'bad']])
            ->assertUnprocessable()->assertJsonValidationErrors(['permission_ids.1', 'permission_ids.2'], 'error.details');
        $this->withToken($g['token'])->putJson($uri, ['permission_ids' => [(string) Str::uuid()]])
            ->assertUnprocessable()->assertJsonValidationErrors(['permission_ids'], 'error.details');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RBAC-04] System roles are immutable through the public role editor.
    public function test_it_rejects_system_role_changes(): void
    {
        $g = $this->graph(grantOrganizationPermission: true);
        $this->withToken($g['token'])->putJson('/api/v1/roles/'.$g['system_role'].'/permissions', ['permission_ids' => []])
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.details.role_id', $g['system_role']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RBAC-04] Foreign and global roles are hidden without explicit global elevation.
    public function test_it_hides_out_of_scope_roles_without_elevation(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->putJson('/api/v1/roles/'.$g['foreign_role'].'/permissions', ['permission_ids' => []])->assertNotFound();
        $this->withToken($g['token'])->putJson('/api/v1/roles/'.$g['global_role'].'/permissions', ['permission_ids' => []])->assertNotFound();
    }

    // [RBAC-04] organizations.manage permits non-system global roles, never foreign tenant roles.
    public function test_it_allows_non_system_global_roles_with_elevation(): void
    {
        $g = $this->graph(grantOrganizationPermission: true);
        $this->withToken($g['token'])->putJson('/api/v1/roles/'.$g['global_role'].'/permissions', ['permission_ids' => [$g['permissions']['sites.read']]])
            ->assertOk()->assertJsonPath('data.role_id', $g['global_role']);
        $this->withToken($g['token'])->putJson('/api/v1/roles/'.$g['foreign_role'].'/permissions', ['permission_ids' => []])->assertNotFound();
    }

    // [RBAC-04] Audit failure rolls back the pivot replacement.
    public function test_it_rolls_back_when_audit_fails(): void
    {
        $g = $this->graph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($g['token'])->putJson('/api/v1/roles/'.$g['target_role'].'/permissions', ['permission_ids' => []])
            ->assertInternalServerError();
        $this->assertDatabaseHas('role_permissions', ['role_id' => $g['target_role'], 'permission_id' => $g['permissions']['users.manage']]);
    }

    // [RBAC-04] Authentication and roles.manage are mandatory.
    public function test_it_requires_authentication_and_roles_manage(): void
    {
        $this->putJson('/api/v1/roles/'.Str::uuid().'/permissions', ['permission_ids' => []])->assertUnauthorized();
        $g = $this->graph(grantRolesPermission: false);
        $this->withToken($g['token'])->putJson('/api/v1/roles/'.$g['target_role'].'/permissions', ['permission_ids' => []])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'roles.manage');
    }

    // [RBAC-04] permissions.manage is independently mandatory.
    public function test_it_requires_permissions_manage(): void
    {
        $g = $this->graph(grantPermissionsPermission: false);
        $this->withToken($g['token'])->putJson('/api/v1/roles/'.$g['target_role'].'/permissions', ['permission_ids' => []])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'permissions.manage');
    }

    // [RBAC-04] Permissions inherited solely from a foreign role cannot authorize mutation.
    public function test_it_rejects_foreign_tenant_authority(): void
    {
        $g = $this->graph(foreignAuthority: true);
        $this->withToken($g['token'])->putJson('/api/v1/roles/'.$g['target_role'].'/permissions', ['permission_ids' => []])->assertForbidden();
    }

    // [RBAC-04] Replacements consume the authenticated throttle budget.
    public function test_it_rate_limits_replacements(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $uri = '/api/v1/roles/'.$g['target_role'].'/permissions';
        $this->withToken($g['token'])->putJson($uri, ['permission_ids' => []])->assertOk();
        $this->withToken($g['token'])->putJson($uri, ['permission_ids' => [$g['permissions']['sites.read']]])->assertTooManyRequests();
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $g['target_role']]);
    }

    // [RBAC-04] Identity DCL supports pivot replacement and append-only audit.
    public function test_it_reuses_identity_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/002_identity_and_audit_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('app.role_permissions,', $dcl);
        $this->assertStringContainsString('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE', $dcl);
        $this->assertStringContainsString('REVOKE UPDATE, DELETE, TRUNCATE ON TABLE app.audit_logs', $dcl);
    }

    /** @return array<string, mixed> */
    private function graph(bool $grantRolesPermission = true, bool $grantPermissionsPermission = true, bool $grantOrganizationPermission = false, bool $foreignAuthority = false): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $admin = (string) Str::uuid();
        $target = (string) Str::uuid();
        $foreign = (string) Str::uuid();
        $system = (string) Str::uuid();
        $global = (string) Str::uuid();
        $foreignAdmin = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => 'RBAC Four Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => 'Foreign RBAC Four Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('users')->insert(['user_id' => $actor, 'organization_id' => $org, 'first_name' => 'RBAC', 'last_name' => 'Manager', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([
            [$admin, $org, 'RBAC Administrator', false], [$target, $org, 'Researcher', false],
            [$foreign, $foreignOrg, 'Foreign Researcher', false], [$foreignAdmin, $foreignOrg, 'Foreign Administrator', false],
            [$system, null, 'System Viewer', true], [$global, null, 'Global Custom', false],
        ] as [$id, $organization, $name, $isSystem]) {
            DB::table('roles')->insert(['role_id' => $id, 'organization_id' => $organization, 'role_name' => $name, 'role_code' => Str::slug($name, '_').'_'.Str::lower(Str::random(6)), 'is_system_role' => $isSystem, 'created_at' => now(), 'updated_at' => now()]);
        }
        $permissions = [];
        foreach (['roles.manage', 'permissions.manage', 'organizations.manage', 'users.manage', 'sites.read'] as $code) {
            $permissions[$code] = (string) Str::uuid();
            DB::table('permissions')->insert(['permission_id' => $permissions[$code], 'permission_code' => $code, 'permission_name' => $code, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (array_filter([$grantRolesPermission ? 'roles.manage' : null, $grantPermissionsPermission ? 'permissions.manage' : null, $grantOrganizationPermission ? 'organizations.manage' : null]) as $code) {
            DB::table('role_permissions')->insert(['role_id' => $foreignAuthority ? $foreignAdmin : $admin, 'permission_id' => $permissions[$code], 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('role_permissions')->insert(['role_id' => $target, 'permission_id' => $permissions['users.manage'], 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $foreignAuthority ? $foreignAdmin : $admin, 'created_at' => now(), 'updated_at' => now()]);

        return ['target_role' => $target, 'foreign_role' => $foreign, 'system_role' => $system, 'global_role' => $global, 'permissions' => $permissions, 'token' => User::findOrFail($actor)->createToken('rbac-four')->plainTextToken];
    }
}
