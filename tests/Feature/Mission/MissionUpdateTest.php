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

class MissionUpdateTest extends TestCase
{
    use RefreshDatabase;

    // [MSN-04] Planning fields update atomically with before/after audit evidence.
    public function test_it_updates_planning_fields(): void
    {
        $g = $this->graph();
        $r = $this->withHeaders(['Authorization' => 'Bearer '.$g['token'], 'X-Request-ID' => 'req_msn_04'])->patchJson('/api/v1/missions/'.$g['mission'], ['site_id' => $g['site2'], 'mission_code' => ' updated ', 'mission_title' => ' Updated title ', 'planned_start_at' => '2026-09-01T08:00:00Z', 'planned_end_at' => '2026-09-01T10:00:00Z', 'coverage_target_hectares' => '20.1250']);
        $r->assertOk()->assertJsonPath('data.site_id', $g['site2'])->assertJsonPath('data.mission_code', 'UPDATED')->assertJsonPath('data.status', 'planned');
        $a = AuditLog::query()->sole();
        $this->assertSame('mission.update', $a->action);
        $this->assertSame('ORIGINAL', $a->old_values['mission_code']);
        $this->assertSame('UPDATED', $a->new_values['mission_code']);
        $this->assertSame('req_msn_04', $a->request_id);
    }

    // [MSN-04] Non-planned lifecycle states return conflict.
    public function test_it_rejects_non_planned_missions(): void
    {
        $g = $this->graph(status: 'in_progress');
        $this->withToken($g['token'])->patchJson('/api/v1/missions/'.$g['mission'], ['mission_title' => 'No'])->assertConflict()->assertJsonPath('error.code', 'CONFLICT')->assertJsonPath('error.details.current_status', 'in_progress');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [MSN-04] Empty, reversed, duplicate and malformed changes fail validation.
    public function test_it_validates_partial_updates(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->patchJson('/api/v1/missions/'.$g['mission'], [])->assertUnprocessable()->assertJsonValidationErrors(['request'], 'error.details');
        $this->withToken($g['token'])->patchJson('/api/v1/missions/'.$g['mission'], ['planned_start_at' => '2026-09-02', 'planned_end_at' => '2026-09-01', 'coverage_target_hectares' => '1.23456'])->assertUnprocessable()->assertJsonValidationErrors(['planned_end_at', 'coverage_target_hectares'], 'error.details');
    }

    // [MSN-04] Foreign target missions and replacement sites are hidden.
    public function test_it_enforces_tenant_scope(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->patchJson('/api/v1/missions/'.$g['foreign_mission'], ['mission_title' => 'No'])->assertNotFound();
        $this->withToken($g['token'])->patchJson('/api/v1/missions/'.$g['mission'], ['site_id' => $g['foreign_site']])->assertNotFound();
    }

    // [MSN-04] Audit failure rolls back the update.
    public function test_it_rolls_back_on_audit_failure(): void
    {
        $g = $this->graph();
        $a = Mockery::mock(AuditLogger::class);
        $a->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $a);
        $this->withToken($g['token'])->patchJson('/api/v1/missions/'.$g['mission'], ['mission_title' => 'Changed'])->assertInternalServerError();
        $this->assertDatabaseHas('survey_missions', ['mission_id' => $g['mission'], 'mission_title' => 'Original']);
    }

    // [MSN-04] Authentication and tenant-valid missions.update are required.
    public function test_it_enforces_authorization(): void
    {
        $g = $this->graph(local: false);
        $this->patchJson('/api/v1/missions/'.$g['mission'], ['mission_title' => 'X'])->assertUnauthorized();
        $this->withToken($g['token'])->patchJson('/api/v1/missions/'.$g['mission'], ['mission_title' => 'X'])->assertForbidden();
    }

    // [MSN-04] Foreign role permissions are ignored.
    public function test_it_rejects_foreign_permission(): void
    {
        $g = $this->graph(local: false, foreign: true);
        $this->withToken($g['token'])->patchJson('/api/v1/missions/'.$g['mission'], ['mission_title' => 'X'])->assertForbidden();
    }

    // [MSN-04] Updates are throttled.
    public function test_it_rate_limits_updates(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $this->withToken($g['token'])->patchJson('/api/v1/missions/'.$g['mission'], ['mission_title' => 'One'])->assertOk();
        $this->withToken($g['token'])->patchJson('/api/v1/missions/'.$g['mission'], ['mission_title' => 'Two'])->assertTooManyRequests();
    }

    /** @return array<string,string> */
    private function graph(bool $local = true, bool $foreign = false, string $status = 'planned'): array
    {
        $o = (string) Str::uuid();
        $fo = (string) Str::uuid();
        $u = (string) Str::uuid();
        $fu = (string) Str::uuid();
        $r = (string) Str::uuid();
        $fr = (string) Str::uuid();
        $p = (string) Str::uuid();
        $s = (string) Str::uuid();
        $s2 = (string) Str::uuid();
        $fs = (string) Str::uuid();
        DB::table('organizations')->insert([['organization_id' => $o, 'organization_name' => 'O', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $fo, 'organization_name' => 'F', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($u, $o, 'update@example.test');
        $this->user($fu, $fo, 'fupdate@example.test');
        DB::table('roles')->insert([['role_id' => $r, 'organization_id' => $o, 'role_name' => 'Updater', 'role_code' => 'updater', 'created_at' => now(), 'updated_at' => now()], ['role_id' => $fr, 'organization_id' => $fo, 'role_name' => 'Foreign', 'role_code' => 'foreign', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('permissions')->insert(['permission_id' => $p, 'permission_code' => 'missions.update', 'permission_name' => 'Update', 'created_at' => now(), 'updated_at' => now()]);
        if ($local || $foreign) {
            $ar = $foreign ? $fr : $r;
            DB::table('role_permissions')->insert(['role_id' => $ar, 'permission_id' => $p, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $u, 'role_id' => $ar, 'created_at' => now(), 'updated_at' => now()]);
        }$this->site($s, $o, $u, 'SITE1');
        $this->site($s2, $o, $u, 'SITE2');
        $this->site($fs, $fo, $fu, 'FSITE');
        $m = (string) Str::uuid();
        $fm = (string) Str::uuid();
        $this->mission($m, $s, $u, 'ORIGINAL', $status);
        $this->mission($fm, $fs, $fu, 'FOREIGN', 'planned');

        return ['mission' => $m, 'foreign_mission' => $fm, 'site2' => $s2, 'foreign_site' => $fs, 'token' => User::query()->findOrFail($u)->createToken('update', ['*'], now()->addHour())->plainTextToken];
    }

    private function user(string $id, string $o, string $e): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $o, 'first_name' => 'M', 'last_name' => 'U', 'email' => $e, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $o, string $u, string $c): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $o, 'site_name' => $c, 'site_code' => $c, 'province' => 'P', 'city_municipality' => 'C', 'environment_type' => 'coastal', 'status' => 'active', 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $s, string $u, string $c, string $status): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $s, 'mission_code' => $c, 'mission_title' => 'Original', 'mission_objective' => 'Objective', 'mission_status' => $status, 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
    }
}
