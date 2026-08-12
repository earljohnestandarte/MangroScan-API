<?php

namespace Tests\Feature\User;

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

class UserUpdateTest extends TestCase
{
    use RefreshDatabase;

    // [USR-04] Managers update normalized safe profile fields with before/after audit evidence.
    public function test_it_updates_a_user_profile(): void
    {
        $g = $this->graph();
        $response = $this->withToken($g['token'])
            ->withHeaders(['X-Request-ID' => 'req_usr_04', 'User-Agent' => 'User Update Test'])
            ->patchJson('/api/v1/users/'.$g['target'], [
                'first_name' => ' Updated ', 'middle_name' => ' Middle ', 'last_name' => ' Person ',
                'position_title' => ' Senior Researcher ', 'email' => ' UPDATED@EXAMPLE.TEST ',
            ]);

        $response->assertOk()->assertHeader('X-Request-ID', 'req_usr_04')
            ->assertJsonPath('data.user_id', $g['target'])
            ->assertJsonPath('data.first_name', 'Updated')->assertJsonPath('data.middle_name', 'Middle')
            ->assertJsonPath('data.last_name', 'Person')->assertJsonPath('data.position_title', 'Senior Researcher')
            ->assertJsonPath('data.email', 'updated@example.test')->assertJsonPath('data.is_active', true)
            ->assertJsonPath('meta.request_id', 'req_usr_04');

        $audit = AuditLog::query()->sole();
        $this->assertSame('user.update', $audit->action);
        $this->assertSame($g['target'], $audit->record_id);
        $this->assertSame('Target', $audit->old_values['first_name']);
        $this->assertSame('Updated', $audit->new_values['first_name']);
        $this->assertArrayNotHasKey('password', $audit->old_values);
        $this->assertArrayNotHasKey('password', $audit->new_values);
        $this->assertSame('req_usr_04', $audit->request_id);
    }

    // [USR-04] Nullable profile extensions can be cleared while omitted fields remain unchanged.
    public function test_it_clears_nullable_fields_and_preserves_omitted_fields(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->patchJson('/api/v1/users/'.$g['target'], [
            'middle_name' => null, 'position_title' => null,
        ])->assertOk()->assertJsonPath('data.first_name', 'Target')
            ->assertJsonPath('data.middle_name', null)->assertJsonPath('data.position_title', null)
            ->assertJsonPath('data.email', 'target@example.test');
    }

    // [USR-04] Empty, unknown and malformed updates fail validation.
    public function test_it_validates_partial_profile_updates(): void
    {
        $g = $this->graph();
        $uri = '/api/v1/users/'.$g['target'];
        $this->withToken($g['token'])->patchJson($uri, [])->assertUnprocessable()
            ->assertJsonValidationErrors(['request'], 'error.details');
        $this->withToken($g['token'])->patchJson($uri, ['organization_id' => $g['foreign_org']])
            ->assertUnprocessable()->assertJsonValidationErrors(['request'], 'error.details');
        $this->withToken($g['token'])->patchJson($uri, [
            'first_name' => ' ', 'last_name' => str_repeat('x', 101), 'email' => 'bad',
        ])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'email'], 'error.details');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [USR-04] Active and soft-deleted email addresses remain reserved case-insensitively.
    public function test_it_rejects_reserved_email_addresses(): void
    {
        $g = $this->graph();
        foreach ([' duplicate@example.test ', ' DELETED@EXAMPLE.TEST '] as $email) {
            $this->withToken($g['token'])->patchJson('/api/v1/users/'.$g['target'], ['email' => $email])
                ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        }
        $this->assertDatabaseHas('users', ['user_id' => $g['target'], 'email' => 'target@example.test']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [USR-04] Normal managers cannot enumerate foreign, deleted, missing or malformed users.
    public function test_it_hides_out_of_scope_and_unavailable_users(): void
    {
        $g = $this->graph();
        foreach ([$g['foreign_target'], $g['deleted'], (string) Str::uuid(), 'bad-id'] as $id) {
            $this->withToken($g['token'])->patchJson('/api/v1/users/'.$id, ['first_name' => 'No'])
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    // [USR-04] organizations.manage explicitly permits a foreign-organization target.
    public function test_it_allows_authorized_cross_organization_updates(): void
    {
        $g = $this->graph(grantOrganizationPermission: true);
        $this->withToken($g['token'])->patchJson('/api/v1/users/'.$g['foreign_target'], ['first_name' => 'Elevated'])
            ->assertOk()->assertJsonPath('data.first_name', 'Elevated')
            ->assertJsonPath('data.organization_id', $g['foreign_org']);
    }

    // [USR-04] Audit persistence failure restores the prior profile.
    public function test_it_rolls_back_when_audit_fails(): void
    {
        $g = $this->graph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($g['token'])->patchJson('/api/v1/users/'.$g['target'], ['first_name' => 'Changed'])
            ->assertInternalServerError();
        $this->assertDatabaseHas('users', ['user_id' => $g['target'], 'first_name' => 'Target']);
    }

    // [USR-04] Authentication and users.manage are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->patchJson('/api/v1/users/'.Str::uuid(), ['first_name' => 'No'])->assertUnauthorized();
        $g = $this->graph(grantUserPermission: false);
        $this->withToken($g['token'])->patchJson('/api/v1/users/'.$g['target'], ['first_name' => 'No'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'users.manage');
    }

    // [USR-04] A foreign-role assignment cannot authorize profile updates.
    public function test_it_rejects_foreign_tenant_permission(): void
    {
        $g = $this->graph(grantUserPermission: false, grantForeignPermission: true);
        $this->withToken($g['token'])->patchJson('/api/v1/users/'.$g['target'], ['first_name' => 'No'])
            ->assertForbidden();
    }

    // [USR-04] Updates consume the authenticated throttle budget.
    public function test_it_rate_limits_profile_updates(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $uri = '/api/v1/users/'.$g['target'];
        $this->withToken($g['token'])->patchJson($uri, ['first_name' => 'First'])->assertOk();
        $this->withToken($g['token'])->patchJson($uri, ['first_name' => 'Second'])->assertTooManyRequests();
        $this->assertDatabaseHas('users', ['user_id' => $g['target'], 'first_name' => 'First']);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [USR-04] Existing identity DCL supports user update and append-only audit insertion.
    public function test_it_reuses_identity_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/002_identity_and_audit_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('app.users,', $dcl);
        $this->assertStringContainsString('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE', $dcl);
        $this->assertStringContainsString('GRANT SELECT, INSERT ON TABLE app.audit_logs', $dcl);
        $this->assertStringContainsString('REVOKE UPDATE, DELETE, TRUNCATE ON TABLE app.audit_logs', $dcl);
    }

    /** @return array<string, string> */
    private function graph(bool $grantUserPermission = true, bool $grantOrganizationPermission = false, bool $grantForeignPermission = false): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $target = (string) Str::uuid();
        $duplicate = (string) Str::uuid();
        $deleted = (string) Str::uuid();
        $foreignTarget = (string) Str::uuid();
        $role = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => 'User Update Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => 'Foreign User Update Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, 'Actor', 'actor-'.Str::uuid().'@example.test');
        $this->user($target, $org, 'Target', 'target@example.test', middle: 'Existing', title: 'Researcher');
        $this->user($duplicate, $org, 'Duplicate', 'duplicate@example.test');
        $this->user($deleted, $org, 'Deleted', 'deleted@example.test', deleted: true);
        $this->user($foreignTarget, $foreignOrg, 'Foreign', 'foreign@example.test');
        DB::table('roles')->insert([
            ['role_id' => $role, 'organization_id' => $org, 'role_name' => 'User Manager', 'role_code' => 'user_update_manager_'.Str::lower(Str::random(8)), 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $foreignOrg, 'role_name' => 'Foreign User Manager', 'role_code' => 'foreign_user_update_manager_'.Str::lower(Str::random(8)), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionCodes = array_filter([$grantUserPermission || $grantForeignPermission ? 'users.manage' : null, $grantOrganizationPermission ? 'organizations.manage' : null]);
        foreach ($permissionCodes as $code) {
            $permission = (string) Str::uuid();
            DB::table('permissions')->insert(['permission_id' => $permission, 'permission_code' => $code, 'permission_name' => $code, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('role_permissions')->insert(['role_id' => $grantForeignPermission && $code === 'users.manage' ? $foreignRole : $role, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $grantForeignPermission ? $foreignRole : $role, 'created_at' => now(), 'updated_at' => now()]);

        return ['organization_id' => $org, 'foreign_org' => $foreignOrg, 'target' => $target, 'deleted' => $deleted, 'foreign_target' => $foreignTarget, 'token' => User::findOrFail($actor)->createToken('user-update')->plainTextToken];
    }

    private function user(string $id, string $org, string $first, string $email, ?string $middle = null, ?string $title = null, bool $deleted = false): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => $first, 'middle_name' => $middle, 'last_name' => 'User', 'position_title' => $title, 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }
}
