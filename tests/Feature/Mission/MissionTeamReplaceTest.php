<?php

namespace Tests\Feature\Mission;

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

class MissionTeamReplaceTest extends TestCase
{
    use RefreshDatabase;

    // [TEAM-01] Full replacement is sorted, persisted, and audited.
    public function test_it_replaces_the_team(): void
    {
        $g = $this->graph();
        $r = $this->withHeaders(['Authorization' => 'Bearer '.$g['token'], 'X-Request-ID' => 'req_team_01'])->putJson('/api/v1/missions/'.$g['mission'].'/team', ['members' => [['user_id' => $g['member'], 'team_role' => 'pilot'], ['user_id' => $g['actor'], 'team_role' => 'observer']]]);
        $r->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.team_role', 'observer')->assertJsonPath('data.1.team_role', 'pilot');
        $this->assertDatabaseMissing('mission_team_members', ['team_role' => 'researcher']);
        $a = AuditLog::query()->sole();
        $this->assertSame('mission.team.replace', $a->action);
        $this->assertCount(1, $a->old_values['members']);
        $this->assertCount(2, $a->new_values['members']);
    }

    // [TEAM-01] Empty replacement removes every assignment.
    public function test_it_accepts_an_empty_team(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->putJson('/api/v1/missions/'.$g['mission'].'/team', ['members' => []])->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('mission_team_members', 0);
    }

    // [TEAM-01] Duplicate pairs and foreign/missing/deleted users fail validation.
    public function test_it_validates_members(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->putJson('/api/v1/missions/'.$g['mission'].'/team', ['members' => [['user_id' => $g['foreign_user'], 'team_role' => 'pilot'], ['user_id' => $g['foreign_user'], 'team_role' => 'pilot']]])->assertUnprocessable()->assertJsonValidationErrors(['members.1'], 'error.details');
        $this->withToken($g['token'])->putJson('/api/v1/missions/'.$g['mission'].'/team', ['members' => [['user_id' => $g['foreign_user'], 'team_role' => 'pilot']]])->assertUnprocessable()->assertJsonValidationErrors(['members'], 'error.details');
        $this->assertDatabaseHas('mission_team_members', ['team_role' => 'researcher']);
    }

    // [TEAM-01] Approved/non-planned missions return conflict.
    public function test_it_enforces_preapproval_workflow(): void
    {
        $g = $this->graph(status: 'in_progress');
        $this->withToken($g['token'])->putJson('/api/v1/missions/'.$g['mission'].'/team', ['members' => []])->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
    }

    // [TEAM-01] Audit failure rolls back pivot replacement.
    public function test_it_rolls_back_on_audit_failure(): void
    {
        $g = $this->graph();
        $a = Mockery::mock(AuditLogger::class);
        $a->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $a);
        $this->withToken($g['token'])->putJson('/api/v1/missions/'.$g['mission'].'/team', ['members' => []])->assertInternalServerError();
        $this->assertDatabaseHas('mission_team_members', ['team_role' => 'researcher']);
    }

    // [TEAM-01] Authentication, permission, and tenant mission scope are enforced.
    public function test_it_enforces_access(): void
    {
        $g = $this->graph(permission: false);
        $this->putJson('/api/v1/missions/'.$g['mission'].'/team', ['members' => []])->assertUnauthorized();
        $this->withToken($g['token'])->putJson('/api/v1/missions/'.$g['mission'].'/team', ['members' => []])->assertForbidden();
    }

    // [TEAM-01] Replacement is throttled.
    public function test_it_rate_limits_replacement(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $this->withToken($g['token'])->putJson('/api/v1/missions/'.$g['mission'].'/team', ['members' => []])->assertOk();
        $this->withToken($g['token'])->putJson('/api/v1/missions/'.$g['mission'].'/team', ['members' => []])->assertTooManyRequests();
    }

    /** @return array<string,string> */
    private function graph(bool $permission = true, string $status = 'planned'): array
    {
        $o = (string) Str::uuid();
        $fo = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $member = (string) Str::uuid();
        $fu = (string) Str::uuid();
        $role = (string) Str::uuid();
        $p = (string) Str::uuid();
        $s = (string) Str::uuid();
        DB::table('organizations')->insert([['organization_id' => $o, 'organization_name' => 'O', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $fo, 'organization_name' => 'F', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($actor, $o, 'team@example.test');
        $this->user($member, $o, 'member@example.test');
        $this->user($fu, $fo, 'fmember@example.test');
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $o, 'role_name' => 'Team Manager', 'role_code' => 'team_manager', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insert(['permission_id' => $p, 'permission_code' => 'mission_team.manage', 'permission_name' => 'Manage team', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $p, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }$this->site($s, $o, $actor);
        $m = (string) Str::uuid();
        DB::table('survey_missions')->insert(['mission_id' => $m, 'site_id' => $s, 'mission_code' => 'TEAM-MSN', 'mission_title' => 'Mission', 'mission_objective' => 'Objective', 'mission_status' => $status, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mission_team_members')->insert(['mission_team_id' => (string) Str::uuid(), 'mission_id' => $m, 'user_id' => $actor, 'team_role' => 'researcher', 'assigned_at' => now()]);

        return ['actor' => $actor, 'member' => $member, 'foreign_user' => $fu, 'mission' => $m, 'token' => User::query()->findOrFail($actor)->createToken('team', ['*'], now()->addHour())->plainTextToken];
    }

    private function user(string $id, string $o, string $e): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $o, 'first_name' => 'T', 'last_name' => 'M', 'email' => $e, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $o, string $u): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $o, 'site_name' => 'S', 'site_code' => 'TEAM-SITE', 'province' => 'P', 'city_municipality' => 'C', 'environment_type' => 'coastal', 'status' => 'active', 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
    }
}
