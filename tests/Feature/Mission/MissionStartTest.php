<?php

namespace Tests\Feature\Mission;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MissionStartTest extends TestCase
{
    use RefreshDatabase;

    // [MSN-07] An approved planned mission starts at the submitted instant with audit evidence.
    public function test_it_starts_an_approved_mission(): void
    {
        $g = $this->graph();
        $response = $this->withToken($g['token'])->withHeader('X-Request-ID', 'req_msn_07')
            ->postJson('/api/v1/missions/'.$g['mission'].'/start', ['started_at' => '2026-08-12T08:15:00+08:00']);

        $response->assertOk()->assertJsonPath('data.mission_id', $g['mission'])
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.actual_start_at', '2026-08-12T00:15:00+00:00')
            ->assertJsonPath('meta.request_id', 'req_msn_07');
        $audit = AuditLog::query()->sole();
        $this->assertSame('mission.start', $audit->action);
        $this->assertSame($g['mission'], $audit->record_id);
        $this->assertSame('planned', $audit->old_values['mission_status']);
        $this->assertSame('2026-08-12T00:15:00+00:00', $audit->new_values['actual_start_at']);
    }

    public function test_it_uses_server_time_when_started_at_is_omitted_or_null(): void
    {
        Carbon::setTestNow('2026-08-12T03:00:00+00:00');
        foreach ([[], ['started_at' => null]] as $payload) {
            $g = $this->graph();
            $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/start', $payload)
                ->assertOk()->assertJsonPath('data.actual_start_at', '2026-08-12T03:00:00+00:00');
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_it_requires_approval_and_the_planned_state(): void
    {
        $unapproved = $this->graph(approved: false);
        $this->withToken($unapproved['token'])->postJson('/api/v1/missions/'.$unapproved['mission'].'/start')
            ->assertConflict()->assertJsonPath('error.details.approved', false);

        foreach (['in_progress', 'completed', 'cancelled', 'failed'] as $status) {
            $this->app['auth']->forgetGuards();
            $g = $this->graph(status: $status);
            $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/start')
                ->assertConflict()->assertJsonPath('error.details.current_status', $status);
        }
    }

    public function test_it_validates_an_optional_start_instant(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/start', ['started_at' => 'not-a-date'])
            ->assertUnprocessable()->assertJsonValidationErrors(['started_at'], 'error.details');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_hides_foreign_deleted_missing_and_malformed_missions(): void
    {
        $g = $this->graph();
        foreach ([$g['foreign_mission'], $g['deleted_mission'], (string) Str::uuid(), 'bad'] as $id) {
            $this->withToken($g['token'])->postJson('/api/v1/missions/'.$id.'/start')
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $g = $this->graph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/start')->assertInternalServerError();
        $this->assertDatabaseHas('survey_missions', [
            'mission_id' => $g['mission'], 'mission_status' => 'planned', 'actual_start_at' => null,
        ]);
    }

    public function test_it_requires_active_authentication_and_missions_update(): void
    {
        $g = $this->graph(permission: false);
        $this->postJson('/api/v1/missions/'.$g['mission'].'/start')->assertUnauthorized();
        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/start')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'missions.update');

        $this->app['auth']->forgetGuards();
        $active = $this->graph();
        DB::table('organizations')->where('organization_id', $active['org'])->update(['status' => 'inactive']);
        $this->withToken($active['token'])->postJson('/api/v1/missions/'.$active['mission'].'/start')
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_it_rejects_a_foreign_organization_permission_grant(): void
    {
        $g = $this->graph(permissionOrganization: 'foreign');
        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/start')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'missions.update');
    }

    public function test_it_rate_limits_start_transitions(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $url = '/api/v1/missions/'.$g['mission'].'/start';
        $this->withToken($g['token'])->postJson($url)->assertOk();
        $this->withToken($g['token'])->postJson($url)->assertTooManyRequests();
    }

    /** @return array<string, string> */
    private function graph(bool $approved = true, string $status = 'planned', bool $permission = true, ?string $permissionOrganization = null): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignUser = (string) Str::uuid();
        $suffix = Str::upper(Str::random(8));
        $role = (string) Str::uuid();
        $permissionId = DB::table('permissions')->where('permission_code', 'missions.update')->value('permission_id')
            ?? (string) Str::uuid();
        $site = (string) Str::uuid();
        $foreignSite = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => 'Mission Start', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => 'Foreign Mission Start', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, Str::uuid().'@test');
        $this->user($foreignUser, $foreignOrg, Str::uuid().'@test');
        DB::table('roles')->insert([
            'role_id' => $role,
            'organization_id' => $permissionOrganization === 'foreign' ? $foreignOrg : $org,
            'role_name' => 'Mission Starter', 'role_code' => 'mission-starter-'.Str::lower(Str::random(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! DB::table('permissions')->where('permission_id', $permissionId)->exists()) {
            DB::table('permissions')->insert([
                'permission_id' => $permissionId, 'permission_code' => 'missions.update',
                'permission_name' => 'Update missions', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->site($site, $org, $actor, 'START-SITE-'.$suffix);
        $this->site($foreignSite, $foreignOrg, $foreignUser, 'FOREIGN-START-SITE-'.$suffix);
        $mission = (string) Str::uuid();
        $foreignMission = (string) Str::uuid();
        $deletedMission = (string) Str::uuid();
        $this->mission($mission, $site, $actor, 'START-MSN-'.$suffix, $status, $approved ? $actor : null);
        $this->mission($foreignMission, $foreignSite, $foreignUser, 'FOREIGN-START-MSN-'.$suffix, 'planned', $foreignUser);
        $this->mission($deletedMission, $site, $actor, 'DELETED-START-MSN-'.$suffix, 'planned', $actor, true);

        return [
            'org' => $org, 'actor' => $actor, 'mission' => $mission, 'foreign_mission' => $foreignMission,
            'deleted_mission' => $deletedMission, 'token' => User::query()->findOrFail($actor)->createToken('mission-start')->plainTextToken,
        ];
    }

    private function user(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId, 'first_name' => 'Mission', 'last_name' => 'Starter',
            'email' => $email, 'password' => Hash::make('password'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function site(string $id, string $organizationId, string $creator, string $code): void
    {
        DB::table('survey_sites')->insert([
            'site_id' => $id, 'organization_id' => $organizationId, 'site_name' => $code, 'site_code' => $code,
            'province' => 'P', 'city_municipality' => 'C', 'environment_type' => 'coastal', 'status' => 'active',
            'created_by' => $creator, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mission(string $id, string $siteId, string $creator, string $code, string $status, ?string $approver, bool $deleted = false): void
    {
        DB::table('survey_missions')->insert([
            'mission_id' => $id, 'site_id' => $siteId, 'mission_code' => $code, 'mission_title' => $code,
            'mission_objective' => 'Survey mangroves', 'mission_status' => $status,
            'created_by' => $creator, 'approved_by' => $approver, 'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }
}
