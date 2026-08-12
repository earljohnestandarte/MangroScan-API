<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserEffectivePermissionsViewTest extends TestCase
{
    use RefreshDatabase;

    // [V-01] The read model returns global and own-tenant permissions only.
    public function test_it_projects_tenant_safe_effective_permissions(): void
    {
        $localOrganization = $this->organization('Local Organization');
        $foreignOrganization = $this->organization('Foreign Organization');
        $activeUser = $this->user($localOrganization, 'active@example.test');
        $inactiveUser = $this->user($localOrganization, 'inactive@example.test', 'inactive');
        $deletedUser = $this->user($localOrganization, 'deleted@example.test', deleted: true);

        $localRole = $this->role($localOrganization, 'Local Researcher');
        $globalRole = $this->role(null, 'Global Viewer');
        $foreignRole = $this->role($foreignOrganization, 'Foreign Administrator');
        $this->assign($activeUser, $localRole, 'missions.read');
        $this->assign($activeUser, $globalRole, 'results.read');
        $this->assign($activeUser, $foreignRole, 'organizations.manage');
        $this->assign($inactiveUser, $localRole, 'missions.read');
        $this->assign($deletedUser, $localRole, 'missions.read');

        $rows = DB::table('v_user_effective_permissions')
            ->where('user_id', $activeUser)
            ->orderBy('permission_code')
            ->get();

        $this->assertSame(['missions.read', 'results.read'], $rows->pluck('permission_code')->all());
        $this->assertSame([$localOrganization, $localOrganization], $rows->pluck('organization_id')->all());
        $this->assertSame(['Local Researcher', 'Global Viewer'], $rows->pluck('role_name')->all());
        $this->assertFalse(DB::table('v_user_effective_permissions')->where('user_id', $inactiveUser)->exists());
        $this->assertFalse(DB::table('v_user_effective_permissions')->where('user_id', $deletedUser)->exists());
    }

    // [V-01] The migration and DCL remain reproducible and read-only.
    public function test_it_versions_the_view_and_read_only_grant(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_070000_create_user_effective_permissions_view.php'));
        $dcl = file_get_contents(database_path('sql/dcl/041_user_effective_permissions_view_grants.sql'));

        $this->assertIsString($migration);
        foreach (['CREATE VIEW v_user_effective_permissions', "u.status = 'active'", 'r.organization_id IS NULL OR r.organization_id = u.organization_id'] as $fragment) {
            $this->assertStringContainsString($fragment, $migration);
        }
        $service = file_get_contents(app_path('Services/Auth/EffectiveAccessService.php'));
        $this->assertIsString($service);
        $this->assertStringContainsString("DB::table('v_user_effective_permissions')", $service);
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.v_user_effective_permissions TO mangroscan_api_rw;', $dcl);
        foreach (['INSERT', 'UPDATE', 'DELETE', 'mangroscan_worker', 'mangroscan_report_ro', 'mangroscan_auditor'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    private function organization(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('organizations')->insert(['organization_id' => $id, 'organization_name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function user(string $organization, string $email, string $status = 'active', bool $deleted = false): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organization, 'first_name' => 'View', 'last_name' => 'User', 'email' => $email, 'password' => 'hashed', 'status' => $status, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);

        return $id;
    }

    private function role(?string $organization, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $id, 'organization_id' => $organization, 'role_name' => $name, 'role_code' => Str::slug($name, '_'), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function assign(string $user, string $role, string $permission): void
    {
        $permissionId = DB::table('permissions')->where('permission_code', $permission)->value('permission_id');
        if (! is_string($permissionId)) {
            $permissionId = (string) Str::uuid();
            DB::table('permissions')->insert(['permission_id' => $permissionId, 'permission_code' => $permission, 'permission_name' => $permission, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('user_roles')->insertOrIgnore(['user_id' => $user, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
    }
}
