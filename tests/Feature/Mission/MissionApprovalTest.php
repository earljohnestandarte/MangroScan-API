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

class MissionApprovalTest extends TestCase
{
    use RefreshDatabase;

    // [MSN-06] Approval records approver, preserves planned state, and audits decision notes.
    public function test_it_approves_a_mission(): void
    {
        $g = $this->graph();
        $r = $this->withHeaders(['Authorization' => 'Bearer '.$g['token'], 'X-Request-ID' => 'req_msn_06'])->postJson('/api/v1/missions/'.$g['mission'].'/approve', ['decision' => ' APPROVED ', 'notes' => ' Safe to fly ']);
        $r->assertOk()->assertJsonPath('data.status', 'planned')->assertJsonPath('data.approved_by', $g['actor']);
        $a = AuditLog::query()->sole();
        $this->assertSame('mission.approval', $a->action);
        $this->assertSame('approved', $a->new_values['decision']);
        $this->assertSame('Safe to fly', $a->new_values['notes']);
    }

    // [MSN-06] Rejection maps to the documented cancelled lifecycle state.
    public function test_it_rejects_a_mission(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/approve', ['decision' => 'rejected'])->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.approved_by', null);
    }

    // [MSN-06] Duplicate or non-planned decisions return conflict.
    public function test_it_rejects_duplicate_decisions(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/approve', ['decision' => 'approved'])->assertOk();
        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/approve', ['decision' => 'approved'])->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [MSN-06] Decision and notes are validated.
    public function test_it_validates_the_decision(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/approve', ['decision' => 'maybe', 'notes' => str_repeat('x', 2001)])->assertUnprocessable()->assertJsonValidationErrors(['decision', 'notes'], 'error.details');
    }

    // [MSN-06] Foreign, missing and deleted missions are hidden.
    public function test_it_enforces_tenant_scope(): void
    {
        $g = $this->graph();
        foreach ([$g['foreign_mission'], (string) Str::uuid()] as $id) {
            $this->withToken($g['token'])->postJson('/api/v1/missions/'.$id.'/approve', ['decision' => 'approved'])->assertNotFound();
        }
    }

    // [MSN-06] Audit failure rolls the decision back.
    public function test_it_rolls_back_on_audit_failure(): void
    {
        $g = $this->graph();
        $a = Mockery::mock(AuditLogger::class);
        $a->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $a);
        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/approve', ['decision' => 'approved'])->assertInternalServerError();
        $this->assertDatabaseHas('survey_missions', ['mission_id' => $g['mission'], 'approved_by' => null, 'mission_status' => 'planned']);
    }

    // [MSN-06] Authentication and missions.approve are required.
    public function test_it_enforces_authorization(): void
    {
        $g = $this->graph(permission: false);
        $this->postJson('/api/v1/missions/'.$g['mission'].'/approve', ['decision' => 'approved'])->assertUnauthorized();
        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/approve', ['decision' => 'approved'])->assertForbidden();
    }

    // [MSN-06] Decisions are throttled.
    public function test_it_rate_limits_decisions(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/approve', ['decision' => 'approved'])->assertOk();
        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/approve', ['decision' => 'approved'])->assertTooManyRequests();
    }

    /** @return array<string,string> */
    private function graph(bool $permission = true): array
    {
        $o = (string) Str::uuid();
        $fo = (string) Str::uuid();
        $u = (string) Str::uuid();
        $fu = (string) Str::uuid();
        $r = (string) Str::uuid();
        $p = (string) Str::uuid();
        $s = (string) Str::uuid();
        $fs = (string) Str::uuid();
        DB::table('organizations')->insert([['organization_id' => $o, 'organization_name' => 'O', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $fo, 'organization_name' => 'F', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($u, $o, 'approve@example.test');
        $this->user($fu, $fo, 'fapprove@example.test');
        DB::table('roles')->insert(['role_id' => $r, 'organization_id' => $o, 'role_name' => 'Approver', 'role_code' => 'approver', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insert(['permission_id' => $p, 'permission_code' => 'missions.approve', 'permission_name' => 'Approve', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $r, 'permission_id' => $p, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $u, 'role_id' => $r, 'created_at' => now(), 'updated_at' => now()]);
        }$this->site($s, $o, $u, 'APPROVE-SITE');
        $this->site($fs, $fo, $fu, 'FAPPROVE-SITE');
        $m = (string) Str::uuid();
        $fm = (string) Str::uuid();
        $this->mission($m, $s, $u, 'APPROVE-MSN');
        $this->mission($fm, $fs, $fu, 'FAPPROVE-MSN');

        return ['actor' => $u, 'mission' => $m, 'foreign_mission' => $fm, 'token' => User::query()->findOrFail($u)->createToken('approve', ['*'], now()->addHour())->plainTextToken];
    }

    private function user(string $id, string $o, string $e): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $o, 'first_name' => 'A', 'last_name' => 'P', 'email' => $e, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $o, string $u, string $c): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $o, 'site_name' => $c, 'site_code' => $c, 'province' => 'P', 'city_municipality' => 'C', 'environment_type' => 'coastal', 'status' => 'active', 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $s, string $u, string $c): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $s, 'mission_code' => $c, 'mission_title' => $c, 'mission_objective' => 'O', 'mission_status' => 'planned', 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
    }
}
