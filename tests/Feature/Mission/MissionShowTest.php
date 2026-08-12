<?php

namespace Tests\Feature\Mission;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MissionShowTest extends TestCase
{
    use RefreshDatabase;

    // [MSN-03] Detail returns the mission, ordered team, and stable flight summary.
    public function test_it_returns_mission_detail(): void
    {
        $g = $this->graph();
        $r = $this->withToken($g['token'])->withHeader('X-Request-ID', 'req_msn_03')->getJson('/api/v1/missions/'.$g['mission_id']);
        $r->assertOk()->assertJsonPath('data.mission.mission_id', $g['mission_id'])->assertJsonCount(2, 'data.team')
            ->assertJsonPath('data.team.0.team_role', 'observer')->assertJsonPath('data.team.1.team_role', 'pilot')
            ->assertJsonPath('data.flight_summary', ['total' => 0, 'planned' => 0, 'flying' => 0, 'completed' => 0, 'aborted' => 0, 'failed' => 0])
            ->assertJsonPath('meta.request_id', 'req_msn_03');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [MSN-03] Foreign, missing, deleted, and malformed IDs share 404.
    public function test_it_hides_unavailable_missions(): void
    {
        $g = $this->graph();
        foreach ([$g['foreign_mission_id'], $g['deleted_mission_id'], (string) Str::uuid()] as $id) {
            $this->withToken($g['token'])->getJson('/api/v1/missions/'.$id)->assertNotFound();
        }
        $this->withToken($g['token'])->getJson('/api/v1/missions/not-a-uuid')->assertNotFound();
    }

    // [MSN-03] Authentication and missions.read are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $g = $this->graph(local: false);
        $this->getJson('/api/v1/missions/'.$g['mission_id'])->assertUnauthorized();
        $this->withToken($g['token'])->getJson('/api/v1/missions/'.$g['mission_id'])->assertForbidden();
    }

    // [MSN-03] Foreign role grants are ignored.
    public function test_it_rejects_foreign_role_permission(): void
    {
        $g = $this->graph(local: false, foreign: true);
        $this->withToken($g['token'])->getJson('/api/v1/missions/'.$g['mission_id'])->assertForbidden();
    }

    // [MSN-03] Detail reads are throttled.
    public function test_it_rate_limits_detail(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $this->withToken($g['token'])->getJson('/api/v1/missions/'.$g['mission_id'])->assertOk();
        $this->withToken($g['token'])->getJson('/api/v1/missions/'.$g['mission_id'])->assertTooManyRequests();
    }

    /** @return array{mission_id:string,foreign_mission_id:string,deleted_mission_id:string,token:string} */
    private function graph(bool $local = true, bool $foreign = false): array
    {
        $org = (string) Str::uuid();
        $fo = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $fu = (string) Str::uuid();
        $role = (string) Str::uuid();
        $fr = (string) Str::uuid();
        $p = (string) Str::uuid();
        $site = (string) Str::uuid();
        $fs = (string) Str::uuid();
        DB::table('organizations')->insert([['organization_id' => $org, 'organization_name' => 'Current', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $fo, 'organization_name' => 'Foreign', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($actor, $org, 'show@example.test');
        $this->user($fu, $fo, 'foreign-show@example.test');
        DB::table('roles')->insert([['role_id' => $role, 'organization_id' => $org, 'role_name' => 'Reader', 'role_code' => 'reader', 'created_at' => now(), 'updated_at' => now()], ['role_id' => $fr, 'organization_id' => $fo, 'role_name' => 'Foreign', 'role_code' => 'foreign', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('permissions')->insert(['permission_id' => $p, 'permission_code' => 'missions.read', 'permission_name' => 'Read missions', 'created_at' => now(), 'updated_at' => now()]);
        if ($local || $foreign) {
            $a = $foreign ? $fr : $role;
            DB::table('role_permissions')->insert(['role_id' => $a, 'permission_id' => $p, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $a, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->site($site, $org, $actor, 'SHOW-SITE');
        $this->site($fs, $fo, $fu, 'FOREIGN-SHOW-SITE');
        $m = (string) Str::uuid();
        $fm = (string) Str::uuid();
        $dm = (string) Str::uuid();
        $this->mission($m, $site, $actor, 'SHOW-MSN');
        $this->mission($fm, $fs, $fu, 'FOREIGN-SHOW-MSN');
        $this->mission($dm, $site, $actor, 'DELETED-SHOW-MSN', true);
        foreach (['pilot', 'observer'] as $r) {
            DB::table('mission_team_members')->insert(['mission_team_id' => (string) Str::uuid(), 'mission_id' => $m, 'user_id' => $actor, 'team_role' => $r, 'assigned_at' => now()]);
        }

        return ['mission_id' => $m, 'foreign_mission_id' => $fm, 'deleted_mission_id' => $dm, 'token' => User::query()->findOrFail($actor)->createToken('show', ['*'], now()->addHour())->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Mission', 'last_name' => 'Reader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $org, string $u, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros', 'city_municipality' => 'City', 'environment_type' => 'coastal', 'status' => 'active', 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $site, string $u, string $code, bool $deleted = false): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $site, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Objective', 'mission_status' => 'planned', 'created_by' => $u, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }
}
