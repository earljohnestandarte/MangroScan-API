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

class MissionCompleteTest extends TestCase
{
    use RefreshDatabase;

    // [MSN-08] A started mission completes after all flights with notes in audit evidence.
    public function test_it_completes_a_mission_after_all_flights(): void
    {
        $g = $this->graph(flightStatuses: ['completed', 'completed']);
        $response = $this->withToken($g['token'])->withHeader('X-Request-ID', 'req_msn_08')
            ->postJson('/api/v1/missions/'.$g['mission'].'/complete', [
                'ended_at' => '2026-08-12T17:30:00+08:00',
                'completion_notes' => ' Field operations complete ',
            ]);

        $response->assertOk()->assertJsonPath('data.mission_id', $g['mission'])
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.actual_end_at', '2026-08-12T09:30:00+00:00')
            ->assertJsonPath('meta.request_id', 'req_msn_08');
        $this->assertArrayNotHasKey('completion_notes', $response->json('data'));
        $audit = AuditLog::query()->sole();
        $this->assertSame('mission.complete', $audit->action);
        $this->assertSame('in_progress', $audit->old_values['mission_status']);
        $this->assertSame('Field operations complete', $audit->new_values['completion_notes']);
        $this->assertSame(2, $audit->new_values['completed_flight_count']);
    }

    public function test_it_uses_server_time_and_null_notes_when_optional_fields_are_omitted_or_null(): void
    {
        Carbon::setTestNow('2026-08-12T10:00:00+00:00');
        foreach ([[], ['ended_at' => null, 'completion_notes' => null]] as $payload) {
            $g = $this->graph(flightStatuses: ['completed']);
            $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/complete', $payload)
                ->assertOk()->assertJsonPath('data.actual_end_at', '2026-08-12T10:00:00+00:00');
            $this->assertNull(AuditLog::query()->latest('created_at')->firstOrFail()->new_values['completion_notes']);
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_it_requires_a_started_mission(): void
    {
        foreach ([['planned', null], ['completed', '2026-08-12T08:00:00Z'], ['cancelled', '2026-08-12T08:00:00Z'], ['failed', '2026-08-12T08:00:00Z']] as [$status, $startedAt]) {
            $g = $this->graph(status: $status, startedAt: $startedAt, flightStatuses: ['completed']);
            $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/complete')
                ->assertConflict()->assertJsonPath('error.details.current_status', $status);
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_it_requires_at_least_one_flight_and_all_flights_completed(): void
    {
        $none = $this->graph(flightStatuses: []);
        $this->withToken($none['token'])->postJson('/api/v1/missions/'.$none['mission'].'/complete')
            ->assertConflict()->assertJsonPath('error.details.flight_count', 0)
            ->assertJsonPath('error.details.incomplete_by_status', []);

        foreach (['planned', 'flying', 'aborted', 'failed'] as $status) {
            $this->app['auth']->forgetGuards();
            $g = $this->graph(flightStatuses: ['completed', $status]);
            $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/complete')
                ->assertConflict()->assertJsonPath('error.details.flight_count', 2)
                ->assertJsonPath('error.details.incomplete_by_status.'.$status, 1);
        }
    }

    public function test_it_validates_input_and_enforces_time_order(): void
    {
        $g = $this->graph(flightStatuses: ['completed']);
        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/complete', [
            'ended_at' => 'invalid', 'completion_notes' => str_repeat('x', 5001),
        ])->assertUnprocessable()->assertJsonValidationErrors(['ended_at', 'completion_notes'], 'error.details');

        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/complete', [
            'ended_at' => '2026-08-12T08:00:00Z',
        ])->assertConflict()->assertJsonPath('error.details.actual_start_at', '2026-08-12T08:00:00+00:00');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_hides_foreign_deleted_missing_and_malformed_missions(): void
    {
        $g = $this->graph(flightStatuses: ['completed']);
        foreach ([$g['foreign_mission'], $g['deleted_mission'], (string) Str::uuid(), 'bad'] as $id) {
            $this->withToken($g['token'])->postJson('/api/v1/missions/'.$id.'/complete')
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $g = $this->graph(flightStatuses: ['completed']);
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/complete')->assertInternalServerError();
        $this->assertDatabaseHas('survey_missions', [
            'mission_id' => $g['mission'], 'mission_status' => 'in_progress', 'actual_end_at' => null,
        ]);
    }

    public function test_it_requires_active_authentication_and_missions_complete(): void
    {
        $g = $this->graph(flightStatuses: ['completed'], permission: false);
        $this->postJson('/api/v1/missions/'.$g['mission'].'/complete')->assertUnauthorized();
        $this->withToken($g['token'])->postJson('/api/v1/missions/'.$g['mission'].'/complete')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'missions.complete');

        $this->app['auth']->forgetGuards();
        $active = $this->graph(flightStatuses: ['completed']);
        DB::table('users')->where('user_id', $active['actor'])->update(['status' => 'inactive']);
        $this->withToken($active['token'])->postJson('/api/v1/missions/'.$active['mission'].'/complete')
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_it_rejects_foreign_permission_and_rate_limits_completion(): void
    {
        $foreign = $this->graph(flightStatuses: ['completed'], permissionOrganization: 'foreign');
        $this->withToken($foreign['token'])->postJson('/api/v1/missions/'.$foreign['mission'].'/complete')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'missions.complete');

        $this->app['auth']->forgetGuards();
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph(flightStatuses: ['completed']);
        $url = '/api/v1/missions/'.$g['mission'].'/complete';
        $this->withToken($g['token'])->postJson($url)->assertOk();
        $this->withToken($g['token'])->postJson($url)->assertTooManyRequests();
    }

    /** @param list<string> $flightStatuses @return array<string, string> */
    private function graph(
        string $status = 'in_progress',
        ?string $startedAt = '2026-08-12T08:00:00Z',
        array $flightStatuses = [],
        bool $permission = true,
        ?string $permissionOrganization = null,
    ): array {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignUser = (string) Str::uuid();
        $suffix = Str::upper(Str::random(8));
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => 'Mission Complete '.$suffix, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => 'Foreign Mission Complete '.$suffix, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, Str::uuid().'@test');
        $this->user($foreignUser, $foreignOrg, Str::uuid().'@test');
        $permissionId = DB::table('permissions')->where('permission_code', 'missions.complete')->value('permission_id') ?? (string) Str::uuid();
        $role = (string) Str::uuid();
        DB::table('roles')->insert([
            'role_id' => $role, 'organization_id' => $permissionOrganization === 'foreign' ? $foreignOrg : $org,
            'role_name' => 'Mission Completer', 'role_code' => 'mission-completer-'.Str::lower(Str::random(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! DB::table('permissions')->where('permission_id', $permissionId)->exists()) {
            DB::table('permissions')->insert([
                'permission_id' => $permissionId, 'permission_code' => 'missions.complete',
                'permission_name' => 'Complete missions', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }

        $site = (string) Str::uuid();
        $foreignSite = (string) Str::uuid();
        $this->site($site, $org, $actor, 'COMPLETE-SITE-'.$suffix);
        $this->site($foreignSite, $foreignOrg, $foreignUser, 'FOREIGN-COMPLETE-SITE-'.$suffix);
        $mission = (string) Str::uuid();
        $foreignMission = (string) Str::uuid();
        $deletedMission = (string) Str::uuid();
        $this->mission($mission, $site, $actor, 'COMPLETE-MSN-'.$suffix, $status, $startedAt);
        $this->mission($foreignMission, $foreignSite, $foreignUser, 'FOREIGN-COMPLETE-MSN-'.$suffix, 'in_progress', $startedAt);
        $this->mission($deletedMission, $site, $actor, 'DELETED-COMPLETE-MSN-'.$suffix, 'in_progress', $startedAt, true);
        $drone = (string) Str::uuid();
        DB::table('drones')->insert([
            'drone_id' => $drone, 'organization_id' => $org, 'drone_name' => 'Complete Drone '.$suffix,
            'serial_number' => 'COMPLETE-'.$suffix, 'status' => 'available', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($flightStatuses as $index => $flightStatus) {
            DB::table('flight_sessions')->insert([
                'flight_session_id' => (string) Str::uuid(), 'mission_id' => $mission, 'drone_id' => $drone,
                'pilot_user_id' => $actor, 'flight_code' => 'COMPLETE-FLT-'.$suffix.'-'.$index,
                'started_at' => '2026-08-12T08:05:00Z',
                'ended_at' => $flightStatus === 'completed' ? '2026-08-12T09:00:00Z' : null,
                'flight_status' => $flightStatus, 'quality_status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [
            'org' => $org, 'actor' => $actor, 'mission' => $mission, 'foreign_mission' => $foreignMission,
            'deleted_mission' => $deletedMission, 'token' => User::query()->findOrFail($actor)->createToken('mission-complete')->plainTextToken,
        ];
    }

    private function user(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId, 'first_name' => 'Mission', 'last_name' => 'Completer',
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

    private function mission(string $id, string $siteId, string $creator, string $code, string $status, ?string $startedAt, bool $deleted = false): void
    {
        DB::table('survey_missions')->insert([
            'mission_id' => $id, 'site_id' => $siteId, 'mission_code' => $code, 'mission_title' => $code,
            'mission_objective' => 'Complete mangrove survey', 'mission_status' => $status,
            'actual_start_at' => $startedAt, 'created_by' => $creator, 'approved_by' => $creator,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null,
        ]);
    }
}
