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

class UserActivationTest extends TestCase
{
    use RefreshDatabase;

    // [USR-05] Deactivation updates state, revokes all target sessions and audits the reason.
    public function test_it_deactivates_a_user_and_revokes_sessions(): void
    {
        $g = $this->graph();
        $response = $this->withToken($g['token'])->withHeaders([
            'X-Request-ID' => 'req_usr_05_deactivate', 'User-Agent' => 'Activation Test',
        ])->postJson('/api/v1/users/'.$g['target'].'/activation', [
            'is_active' => false, 'reason' => ' Extended field leave ',
        ]);

        $response->assertOk()->assertHeader('X-Request-ID', 'req_usr_05_deactivate')
            ->assertJsonPath('data.user_id', $g['target'])->assertJsonPath('data.is_active', false)
            ->assertJsonPath('meta.request_id', 'req_usr_05_deactivate');
        $this->assertDatabaseHas('users', ['user_id' => $g['target'], 'status' => 'inactive']);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $g['target']]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $g['actor']]);
        $audit = AuditLog::query()->sole();
        $this->assertSame('user.activation.update', $audit->action);
        $this->assertSame(['status' => 'active'], $audit->old_values);
        $this->assertSame('inactive', $audit->new_values['status']);
        $this->assertSame('Extended field leave', $audit->new_values['reason']);
        $this->assertSame('req_usr_05_deactivate', $audit->request_id);
    }

    // [USR-05] Activation returns the safe user and does not create or revoke credentials.
    public function test_it_activates_a_user_without_changing_sessions(): void
    {
        $g = $this->graph(targetStatus: 'inactive');
        $tokenCount = DB::table('personal_access_tokens')->where('tokenable_id', $g['target'])->count();
        $this->withToken($g['token'])->postJson('/api/v1/users/'.$g['target'].'/activation', [
            'is_active' => 'true',
        ])->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('users', ['user_id' => $g['target'], 'status' => 'active']);
        $this->assertSame($tokenCount, DB::table('personal_access_tokens')->where('tokenable_id', $g['target'])->count());
        $this->assertNull(AuditLog::query()->sole()->new_values['reason']);
    }

    // [USR-05] Missing/malformed state and oversized reasons fail validation.
    public function test_it_validates_activation_input(): void
    {
        $g = $this->graph();
        $uri = '/api/v1/users/'.$g['target'].'/activation';
        $this->withToken($g['token'])->postJson($uri, [])->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active'], 'error.details');
        $this->withToken($g['token'])->postJson($uri, ['is_active' => 'maybe', 'reason' => str_repeat('x', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_active', 'reason'], 'error.details');
        $this->assertDatabaseHas('users', ['user_id' => $g['target'], 'status' => 'active']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [USR-05] No-op transitions and self-deactivation are workflow conflicts.
    public function test_it_rejects_no_op_and_self_deactivation(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/users/'.$g['target'].'/activation', ['is_active' => true])
            ->assertConflict()->assertJsonPath('error.details.is_active', true);
        $this->withToken($g['token'])->postJson('/api/v1/users/'.$g['actor'].'/activation', ['is_active' => false])
            ->assertConflict()->assertJsonPath('error.details.user_id', $g['actor']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [USR-05] Out-of-scope, deleted, missing and malformed targets are indistinguishable.
    public function test_it_hides_unavailable_users(): void
    {
        $g = $this->graph();
        foreach ([$g['foreign_target'], $g['deleted'], (string) Str::uuid(), 'bad'] as $id) {
            $this->withToken($g['token'])->postJson('/api/v1/users/'.$id.'/activation', ['is_active' => false])
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    // [USR-05] organizations.manage enables a selected cross-organization target.
    public function test_it_allows_authorized_cross_organization_activation(): void
    {
        $g = $this->graph(grantOrganizationPermission: true);
        $this->withToken($g['token'])->postJson('/api/v1/users/'.$g['foreign_target'].'/activation', ['is_active' => false])
            ->assertOk()->assertJsonPath('data.organization_id', $g['foreign_org'])
            ->assertJsonPath('data.is_active', false);
    }

    // [USR-05] Audit failure restores user state and revoked tokens atomically.
    public function test_it_rolls_back_state_and_tokens_when_audit_fails(): void
    {
        $g = $this->graph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($g['token'])->postJson('/api/v1/users/'.$g['target'].'/activation', ['is_active' => false])
            ->assertInternalServerError();
        $this->assertDatabaseHas('users', ['user_id' => $g['target'], 'status' => 'active']);
        $this->assertDatabaseCount('personal_access_tokens', 3);
    }

    // [USR-05] Authentication and users.manage are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->postJson('/api/v1/users/'.Str::uuid().'/activation', ['is_active' => false])->assertUnauthorized();
        $g = $this->graph(grantUserPermission: false);
        $this->withToken($g['token'])->postJson('/api/v1/users/'.$g['target'].'/activation', ['is_active' => false])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'users.manage');
    }

    // [USR-05] Foreign-role permissions cannot authorize state changes.
    public function test_it_rejects_foreign_tenant_permission(): void
    {
        $g = $this->graph(grantUserPermission: false, grantForeignPermission: true);
        $this->withToken($g['token'])->postJson('/api/v1/users/'.$g['target'].'/activation', ['is_active' => false])
            ->assertForbidden();
    }

    // [USR-05] State changes consume the authenticated request budget.
    public function test_it_rate_limits_activation_changes(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $uri = '/api/v1/users/'.$g['target'].'/activation';
        $this->withToken($g['token'])->postJson($uri, ['is_active' => false])->assertOk();
        $this->withToken($g['token'])->postJson($uri, ['is_active' => true])->assertTooManyRequests();
        $this->assertDatabaseHas('users', ['user_id' => $g['target'], 'status' => 'inactive']);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [USR-05] Identity DCL supports user/token mutation plus append-only audit insertion.
    public function test_it_reuses_identity_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/002_identity_and_audit_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('app.users,', $dcl);
        $this->assertStringContainsString('app.personal_access_tokens', $dcl);
        $this->assertStringContainsString('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE', $dcl);
        $this->assertStringContainsString('REVOKE UPDATE, DELETE, TRUNCATE ON TABLE app.audit_logs', $dcl);
    }

    /** @return array<string, string> */
    private function graph(bool $grantUserPermission = true, bool $grantOrganizationPermission = false, bool $grantForeignPermission = false, string $targetStatus = 'active'): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $target = (string) Str::uuid();
        $deleted = (string) Str::uuid();
        $foreignTarget = (string) Str::uuid();
        $role = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => 'Activation Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => 'Foreign Activation Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, 'Actor', 'active');
        $this->user($target, $org, 'Target', $targetStatus);
        $this->user($deleted, $org, 'Deleted', 'active', true);
        $this->user($foreignTarget, $foreignOrg, 'Foreign', 'active');
        DB::table('roles')->insert([
            ['role_id' => $role, 'organization_id' => $org, 'role_name' => 'Activation Manager', 'role_code' => 'activation_manager_'.Str::lower(Str::random(8)), 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $foreignOrg, 'role_name' => 'Foreign Activation Manager', 'role_code' => 'foreign_activation_manager_'.Str::lower(Str::random(8)), 'created_at' => now(), 'updated_at' => now()],
        ]);
        foreach (array_filter([$grantUserPermission || $grantForeignPermission ? 'users.manage' : null, $grantOrganizationPermission ? 'organizations.manage' : null]) as $code) {
            $permission = (string) Str::uuid();
            DB::table('permissions')->insert(['permission_id' => $permission, 'permission_code' => $code, 'permission_name' => $code, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('role_permissions')->insert(['role_id' => $grantForeignPermission && $code === 'users.manage' ? $foreignRole : $role, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $grantForeignPermission ? $foreignRole : $role, 'created_at' => now(), 'updated_at' => now()]);
        $actorModel = User::findOrFail($actor);
        $targetModel = User::withTrashed()->findOrFail($target);
        $actorToken = $actorModel->createToken('activation-actor')->plainTextToken;
        $targetModel->createToken('activation-target-1');
        $targetModel->createToken('activation-target-2');

        return ['actor' => $actor, 'organization_id' => $org, 'foreign_org' => $foreignOrg, 'target' => $target, 'deleted' => $deleted, 'foreign_target' => $foreignTarget, 'token' => $actorToken];
    }

    private function user(string $id, string $org, string $name, string $status, bool $deleted = false): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => $name, 'last_name' => 'User', 'email' => Str::lower($name).'-'.Str::uuid().'@example.test', 'password' => Hash::make('password'), 'status' => $status, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }
}
