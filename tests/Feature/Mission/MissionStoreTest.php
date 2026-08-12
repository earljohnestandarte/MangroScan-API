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

class MissionStoreTest extends TestCase
{
    use RefreshDatabase;

    // [MSN-02] Creation persists one normalized planned mission and immutable audit.
    public function test_it_creates_a_planned_mission(): void
    {
        $g = $this->graph();
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$g['token'], 'X-Request-ID' => 'req_msn_02', 'User-Agent' => 'Mission Test'])
            ->postJson('/api/v1/missions', $this->payload($g));
        $response->assertCreated()->assertJsonPath('data.site_id', $g['site_id'])
            ->assertJsonPath('data.mission_code', 'MSN-NEW')->assertJsonPath('data.status', 'planned')
            ->assertJsonPath('data.coverage_target_hectares', '12.5000')->assertJsonPath('meta.request_id', 'req_msn_02');
        $id = $response->json('data.mission_id');
        $this->assertDatabaseHas('survey_missions', ['mission_id' => $id, 'created_by' => $g['actor_id'], 'mission_status' => 'planned']);
        $audit = AuditLog::query()->sole();
        $this->assertSame('mission.create', $audit->action);
        $this->assertSame($id, $audit->record_id);
        $this->assertSame('MSN-NEW', $audit->new_values['mission_code']);
        $this->assertSame('req_msn_02', $audit->request_id);
    }

    // [MSN-02] Required fields, unique code, planning order, and numeric scale are validated.
    public function test_it_validates_mission_creation(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/missions', $this->payload($g))->assertCreated();
        $this->withToken($g['token'])->postJson('/api/v1/missions', [
            'site_id' => 'invalid', 'mission_code' => ' msn-new ', 'mission_title' => ' ',
            'mission_objective' => '', 'planned_start_at' => '2026-08-12T10:00:00Z',
            'planned_end_at' => '2026-08-12T09:00:00Z', 'coverage_target_hectares' => '1.23456',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'site_id', 'mission_code', 'mission_title', 'mission_objective', 'planned_end_at', 'coverage_target_hectares',
        ], 'error.details');
        $this->assertDatabaseCount('survey_missions', 1);
    }

    // [MSN-02] Foreign and missing site UUIDs are hidden.
    public function test_it_hides_unavailable_sites(): void
    {
        $g = $this->graph();
        foreach ([$g['foreign_site_id'], (string) Str::uuid()] as $id) {
            $p = $this->payload($g);
            $p['site_id'] = $id;
            $this->withToken($g['token'])->postJson('/api/v1/missions', $p)->assertNotFound();
        }
        $this->assertDatabaseCount('survey_missions', 0);
    }

    // [MSN-02] Audit failure rolls creation back.
    public function test_it_rolls_back_when_audit_fails(): void
    {
        $g = $this->graph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($g['token'])->postJson('/api/v1/missions', $this->payload($g))->assertInternalServerError();
        $this->assertDatabaseCount('survey_missions', 0);
    }

    // [MSN-02] Authentication and current-tenant missions.create are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->postJson('/api/v1/missions', [])->assertUnauthorized();
        $g = $this->graph(local: false);
        $this->withToken($g['token'])->postJson('/api/v1/missions', $this->payload($g))->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'missions.create');
    }

    // [MSN-02] A foreign role cannot authorize creation.
    public function test_it_rejects_a_foreign_role_grant(): void
    {
        $g = $this->graph(local: false, foreign: true);
        $this->withToken($g['token'])->postJson('/api/v1/missions', $this->payload($g))->assertForbidden();
    }

    // [MSN-02] Throttling prevents a second mission and audit.
    public function test_it_rate_limits_creation(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/missions', $this->payload($g))->assertCreated();
        $p = $this->payload($g);
        $p['mission_code'] = 'MSN-SECOND';
        $this->withToken($g['token'])->postJson('/api/v1/missions', $p)->assertTooManyRequests();
        $this->assertDatabaseCount('survey_missions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /** @param array<string,string> $g @return array<string,mixed> */
    private function payload(array $g): array
    {
        return ['site_id' => $g['site_id'], 'mission_code' => ' msn-new ', 'mission_title' => ' Coastal Survey ',
            'mission_objective' => ' Map mangrove growth ', 'planned_start_at' => '2026-08-12T08:00:00Z',
            'planned_end_at' => '2026-08-12T10:00:00Z', 'coverage_target_hectares' => '12.5000'];
    }

    /** @return array{actor_id:string,site_id:string,foreign_site_id:string,token:string} */
    private function graph(bool $local = true, bool $foreign = false): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignUser = (string) Str::uuid();
        $role = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        $permission = (string) Str::uuid();
        $site = (string) Str::uuid();
        $foreignSite = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => 'Current', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => 'Foreign', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($actor, $org, 'mission-create@example.test');
        $this->user($foreignUser, $foreignOrg, 'foreign-mission-create@example.test');
        DB::table('roles')->insert([
            ['role_id' => $role, 'organization_id' => $org, 'role_name' => 'Creator', 'role_code' => 'creator', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $foreignOrg, 'role_name' => 'Foreign Creator', 'role_code' => 'foreign_creator', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('permissions')->insert(['permission_id' => $permission, 'permission_code' => 'missions.create', 'permission_name' => 'Create missions', 'created_at' => now(), 'updated_at' => now()]);
        if ($local || $foreign) {
            $assigned = $foreign ? $foreignRole : $role;
            DB::table('role_permissions')->insert(['role_id' => $assigned, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $assigned, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->site($site, $org, $actor, 'CREATE-SITE');
        $this->site($foreignSite, $foreignOrg, $foreignUser, 'FOREIGN-CREATE-SITE');

        return ['actor_id' => $actor, 'site_id' => $site, 'foreign_site_id' => $foreignSite, 'token' => User::query()->findOrFail($actor)->createToken('Mission create test', ['*'], now()->addHour())->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Mission', 'last_name' => 'Creator', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $org, string $creator, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $creator, 'created_at' => now(), 'updated_at' => now()]);
    }
}
