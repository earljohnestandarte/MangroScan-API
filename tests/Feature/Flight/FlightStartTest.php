<?php

namespace Tests\Feature\Flight;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FlightStartTest extends TestCase
{
    use RefreshDatabase;

    // [FLT-05] A latest passed pre-flight transitions planned to flying with Point(4326) evidence.
    public function test_it_starts_a_ready_flight(): void
    {
        $graph = $this->createGraph();
        $this->insertChecklist($graph['flight_id'], $graph['actor_id'], 'passed', now());

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$graph['token'],
            'X-Request-ID' => 'req_flt_05_success',
            'User-Agent' => 'Flight Start Test',
        ])->postJson('/api/v1/flights/'.$graph['flight_id'].'/start', $this->payload());

        $response
            ->assertOk()
            ->assertJsonPath('data.flight_session_id', $graph['flight_id'])
            ->assertJsonPath('data.status', 'flying')
            ->assertJsonPath('data.takeoff_location.type', 'Point')
            ->assertJsonPath('data.takeoff_location.coordinates.0', 123.9)
            ->assertJsonPath('data.takeoff_location.coordinates.1', 10.2)
            ->assertJsonPath('meta.request_id', 'req_flt_05_success');

        $this->assertTrue(
            CarbonImmutable::parse($response->json('data.started_at'))
                ->equalTo(CarbonImmutable::parse('2026-08-12T08:30:00Z')),
            (string) $response->json('data.started_at'),
        );

        $this->assertDatabaseHas('flight_sessions', [
            'flight_session_id' => $graph['flight_id'],
            'flight_status' => 'flying',
        ]);
        $audit = AuditLog::query()->sole();
        $this->assertSame('flight.start', $audit->action);
        $this->assertSame('planned', $audit->old_values['flight_status']);
        $this->assertSame('flying', $audit->new_values['flight_status']);
        $this->assertSame('req_flt_05_success', $audit->request_id);
    }

    // [FLT-05] The most recent pre-flight evidence is authoritative.
    public function test_it_requires_the_latest_preflight_to_pass(): void
    {
        $graph = $this->createGraph();
        $this->insertChecklist($graph['flight_id'], $graph['actor_id'], 'passed', now()->subMinute());
        $latestId = $this->insertChecklist($graph['flight_id'], $graph['actor_id'], 'conditional', now());

        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/start', $this->payload())
            ->assertConflict()
            ->assertJsonPath('error.details.latest_preflight_status', 'conditional')
            ->assertJsonPath('error.details.latest_preflight_id', $latestId);

        $this->assertDatabaseHas('flight_sessions', [
            'flight_session_id' => $graph['flight_id'],
            'flight_status' => 'planned',
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [FLT-05] Missing readiness evidence and repeated/later-state starts conflict.
    public function test_it_rejects_missing_preflight_and_invalid_flight_states(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/start', $this->payload())
            ->assertConflict()
            ->assertJsonPath('error.details.latest_preflight_status', null);

        $this->insertChecklist($graph['flight_id'], $graph['actor_id'], 'passed', now());
        DB::table('flight_sessions')->where('flight_session_id', $graph['flight_id'])->update(['flight_status' => 'flying']);
        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/start', $this->payload())
            ->assertConflict()
            ->assertJsonPath('error.details.current_status', 'flying');
    }

    // [FLT-05] Timestamp and Point GeoJSON bounds are validated.
    public function test_it_validates_start_input(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/start', [
                'started_at' => 'invalid',
                'takeoff_location' => [
                    'type' => 'LineString',
                    'coordinates' => [181, 91, 1],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'started_at',
                'takeoff_location.type',
                'takeoff_location.coordinates',
                'takeoff_location.coordinates.0',
                'takeoff_location.coordinates.1',
            ], 'error.details');
    }

    // [FLT-05] Foreign, deleted-lineage, and missing flights remain hidden.
    public function test_it_hides_unavailable_flights(): void
    {
        $graph = $this->createGraph();

        foreach ([$graph['foreign_flight_id'], $graph['deleted_flight_id'], (string) Str::uuid()] as $flightId) {
            $this->withToken($graph['token'])
                ->postJson('/api/v1/flights/'.$flightId.'/start', $this->payload())
                ->assertNotFound();
        }
    }

    // [FLT-05] Audit failure restores status, timestamp, and location.
    public function test_it_rolls_back_when_audit_fails(): void
    {
        $graph = $this->createGraph();
        $this->insertChecklist($graph['flight_id'], $graph['actor_id'], 'passed', now());
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/start', $this->payload())
            ->assertInternalServerError();

        $flight = DB::table('flight_sessions')->where('flight_session_id', $graph['flight_id'])->first();
        $this->assertSame('planned', $flight->flight_status);
        $this->assertNull($flight->started_at);
        $this->assertNull($flight->takeoff_location);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [FLT-05] Authentication and tenant-valid flights.start are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $graph = $this->createGraph(localPermission: false);
        $uri = '/api/v1/flights/'.$graph['flight_id'].'/start';

        $this->postJson($uri, $this->payload())->assertUnauthorized();
        $this->withToken($graph['token'])
            ->postJson($uri, $this->payload())
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'flights.start');
    }

    // [FLT-05] A foreign-role permission cannot authorize flight start.
    public function test_it_rejects_a_foreign_role_permission(): void
    {
        $graph = $this->createGraph(localPermission: false, foreignPermission: true);

        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/start', $this->payload())
            ->assertForbidden();
    }

    // [FLT-05] Throttling prevents a repeated transition and audit.
    public function test_it_rate_limits_start_requests(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $this->insertChecklist($graph['flight_id'], $graph['actor_id'], 'passed', now());
        $uri = '/api/v1/flights/'.$graph['flight_id'].'/start';

        $this->withToken($graph['token'])->postJson($uri, $this->payload())->assertOk();
        $this->withToken($graph['token'])->postJson($uri, $this->payload())->assertTooManyRequests();

        $this->assertDatabaseCount('audit_logs', 1);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'started_at' => '2026-08-12T08:30:00Z',
            'takeoff_location' => [
                'type' => 'Point',
                'coordinates' => [123.9, 10.2],
            ],
        ];
    }

    /** @return array<string, string> */
    private function createGraph(bool $localPermission = true, bool $foreignPermission = false): array
    {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        $roleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $permissionId = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => 'Start Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => 'Foreign Start Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, 'start@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, 'foreign-start@example.test');
        DB::table('roles')->insert([
            ['role_id' => $roleId, 'organization_id' => $organizationId, 'role_name' => 'Starter', 'role_code' => 'starter', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => 'Foreign Starter', 'role_code' => 'foreign_starter', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert(['permission_id' => $permissionId, 'permission_code' => 'flights.start', 'permission_name' => 'Start flights', 'created_at' => now(), 'updated_at' => now()]);
        if ($localPermission || $foreignPermission) {
            $assignedRoleId = $foreignPermission ? $foreignRoleId : $roleId;
            DB::table('role_permissions')->insert(['role_id' => $assignedRoleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $assignedRoleId, 'created_at' => now(), 'updated_at' => now()]);
        }
        $siteId = (string) Str::uuid();
        $foreignSiteId = (string) Str::uuid();
        $this->insertSite($siteId, $organizationId, $actorId, 'START-SITE');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignUserId, 'FOREIGN-START-SITE');
        $missionId = (string) Str::uuid();
        $foreignMissionId = (string) Str::uuid();
        $deletedMissionId = (string) Str::uuid();
        $this->insertMission($missionId, $siteId, $actorId, 'START-MISSION');
        $this->insertMission($foreignMissionId, $foreignSiteId, $foreignUserId, 'FOREIGN-START-MISSION');
        $this->insertMission($deletedMissionId, $siteId, $actorId, 'DELETED-START-MISSION', true);
        $droneId = (string) Str::uuid();
        $foreignDroneId = (string) Str::uuid();
        $this->insertDrone($droneId, $organizationId, 'Start Drone', 'START-SERIAL');
        $this->insertDrone($foreignDroneId, $foreignOrganizationId, 'Foreign Start Drone', 'FOREIGN-START-SERIAL');
        $flightId = (string) Str::uuid();
        $foreignFlightId = (string) Str::uuid();
        $deletedFlightId = (string) Str::uuid();
        $this->insertFlight($flightId, $missionId, $droneId, $actorId, 'START-FLIGHT');
        $this->insertFlight($foreignFlightId, $foreignMissionId, $foreignDroneId, $foreignUserId, 'FOREIGN-START-FLIGHT');
        $this->insertFlight($deletedFlightId, $deletedMissionId, $droneId, $actorId, 'DELETED-START-FLIGHT');

        return [
            'actor_id' => $actorId,
            'flight_id' => $flightId,
            'foreign_flight_id' => $foreignFlightId,
            'deleted_flight_id' => $deletedFlightId,
            'token' => User::query()->findOrFail($actorId)->createToken('Flight start test', ['*'], now()->addHour())->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organizationId, 'first_name' => 'Flight', 'last_name' => 'Starter', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertSite(string $id, string $organizationId, string $creatorId, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organizationId, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros', 'city_municipality' => 'Dumaguete', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertMission(string $id, string $siteId, string $creatorId, string $code, bool $deleted = false): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $siteId, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Start flight', 'mission_status' => 'planned', 'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }

    private function insertDrone(string $id, string $organizationId, string $name, string $serial): void
    {
        DB::table('drones')->insert(['drone_id' => $id, 'organization_id' => $organizationId, 'drone_name' => $name, 'serial_number' => $serial, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertFlight(string $id, string $missionId, string $droneId, string $pilotId, string $code): void
    {
        DB::table('flight_sessions')->insert(['flight_session_id' => $id, 'mission_id' => $missionId, 'drone_id' => $droneId, 'pilot_user_id' => $pilotId, 'flight_code' => $code, 'flight_status' => 'planned', 'quality_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertChecklist(string $flightId, string $checkerId, string $status, mixed $createdAt): string
    {
        $id = (string) Str::uuid();
        DB::table('flight_checklists')->insert(['checklist_id' => $id, 'flight_session_id' => $flightId, 'checked_by' => $checkerId, 'checklist_type' => 'pre_flight', 'battery_ok' => true, 'weather_ok' => true, 'gps_ok' => true, 'camera_ok' => true, 'lidar_depth_ok' => true, 'storage_ok' => true, 'overall_status' => $status, 'created_at' => $createdAt]);

        return $id;
    }
}
