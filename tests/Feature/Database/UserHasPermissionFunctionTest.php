<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserHasPermissionFunctionTest extends TestCase
{
    use RefreshDatabase;

    // [R-03] PostgreSQL resolves global and own-tenant access without accepting foreign-role grants.
    public function test_postgresql_function_resolves_effective_permissions(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL function semantics require PostgreSQL.');
        }

        $localOrganization = $this->organization('Routine Local');
        $foreignOrganization = $this->organization('Routine Foreign');
        $activeUser = $this->user($localOrganization, 'routine-active@example.test');
        $inactiveUser = $this->user($localOrganization, 'routine-inactive@example.test', 'inactive');
        $localRole = $this->role($localOrganization, 'Routine Researcher');
        $globalRole = $this->role(null, 'Routine Global');
        $foreignRole = $this->role($foreignOrganization, 'Routine Foreign Admin');
        $this->assign($activeUser, $localRole, 'missions.read');
        $this->assign($activeUser, $globalRole, 'results.read');
        $this->assign($activeUser, $foreignRole, 'organizations.manage');
        $this->assign($inactiveUser, $localRole, 'missions.read');

        $this->assertTrue($this->hasPermission($activeUser, 'missions.read'));
        $this->assertTrue($this->hasPermission($activeUser, 'results.read'));
        $this->assertFalse($this->hasPermission($activeUser, 'organizations.manage'));
        $this->assertFalse($this->hasPermission($activeUser, 'missing.permission'));
        $this->assertFalse($this->hasPermission($inactiveUser, 'missions.read'));
        $this->assertFalse($this->hasPermission((string) Str::uuid(), 'missions.read'));
    }

    // [R-03] The helper remains stable, invoker-scoped and executable only by the API role.
    public function test_it_versions_a_narrow_permission_function_and_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_070100_create_user_has_permission_function.php'));
        $dcl = file_get_contents(database_path('sql/dcl/042_user_has_permission_function_grants.sql'));

        $this->assertIsString($migration);
        foreach (['fn_user_has_permission', 'RETURNS boolean', 'STABLE', 'PARALLEL SAFE', 'v_user_effective_permissions', 'REVOKE ALL'] as $fragment) {
            $this->assertStringContainsString($fragment, $migration);
        }
        $this->assertStringNotContainsString('SECURITY DEFINER', $migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT EXECUTE ON FUNCTION app.fn_user_has_permission(uuid, text) TO mangroscan_api_rw;', $dcl);
        foreach (['mangroscan_worker', 'mangroscan_report_ro', 'mangroscan_auditor', 'INSERT', 'UPDATE', 'DELETE'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    private function hasPermission(string $user, string $permission): bool
    {
        return (bool) DB::scalar('SELECT app.fn_user_has_permission(?, ?)', [$user, $permission]);
    }

    private function organization(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('organizations')->insert(['organization_id' => $id, 'organization_name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function user(string $organization, string $email, string $status = 'active'): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organization, 'first_name' => 'Routine', 'last_name' => 'User', 'email' => $email, 'password' => 'hashed', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

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
