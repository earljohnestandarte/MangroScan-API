<?php

namespace Tests\Feature\Flight;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class FlightShowTest extends TestCase
{
    use RefreshDatabase;

    // [FLT-03] Detail returns exact flight, ordered checklists, and stable child counts.
    public function test_it_returns_flight_readiness_detail(): void
    {
        $graph = $this->createGraph();

        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_flt_03_success')
            ->getJson('/api/v1/flights/'.$graph['flight_id']);

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_flt_03_success')
            ->assertJsonPath('data.flight.flight_session_id', $graph['flight_id'])
            ->assertJsonPath('data.flight.status', 'planned')
            ->assertJsonPath('data.flight.takeoff_location.type', 'Point')
            ->assertJsonPath('data.checklists.0.checklist_id', $graph['preflight_id'])
            ->assertJsonPath('data.checklists.0.checklist_type', 'pre_flight')
            ->assertJsonPath('data.checklists.0.battery_ok', true)
            ->assertJsonPath('data.checklists.1.checklist_id', $graph['postflight_id'])
            ->assertJsonPath('data.waypoint_count', 3)
            ->assertJsonPath('data.media_count', 0)
            ->assertJsonPath('meta.request_id', 'req_flt_03_success')
            ->assertJsonCount(2, 'data.checklists');

        $this->assertSame([
            'checklist_id',
            'flight_session_id',
            'checked_by',
            'checklist_type',
            'battery_ok',
            'weather_ok',
            'gps_ok',
            'camera_ok',
            'lidar_depth_ok',
            'storage_ok',
            'overall_status',
            'remarks',
            'created_at',
        ], array_keys($response->json('data.checklists.0')));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [FLT-03] Foreign, deleted-lineage, missing, and malformed flights remain hidden.
    public function test_it_hides_unavailable_flights(): void
    {
        $graph = $this->createGraph();

        foreach ([
            $graph['foreign_flight_id'],
            $graph['deleted_mission_flight_id'],
            (string) Str::uuid(),
            'not-a-uuid',
        ] as $flightId) {
            $this->withToken($graph['token'])
                ->getJson('/api/v1/flights/'.$flightId)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    // [FLT-03] Authentication and tenant-valid flights.read are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $graph = $this->createGraph(localPermission: false);
        $uri = '/api/v1/flights/'.$graph['flight_id'];

        $this->getJson($uri)->assertUnauthorized();
        $this->withToken($graph['token'])
            ->getJson($uri)
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'flights.read');
    }

    // [FLT-03] Foreign-role permission grants are ignored.
    public function test_it_rejects_a_foreign_role_permission(): void
    {
        $graph = $this->createGraph(localPermission: false, foreignPermission: true);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/flights/'.$graph['flight_id'])
            ->assertForbidden();
    }

    // [FLT-03] Detail reads share the authenticated request budget.
    public function test_it_rate_limits_flight_detail(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $uri = '/api/v1/flights/'.$graph['flight_id'];

        $this->withToken($graph['token'])->getJson($uri)->assertOk();
        $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_flt_03_throttled')
            ->getJson($uri)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_flt_03_throttled');
    }

    // [FLT-03] Child schema constraints and read-only DCL are version controlled.
    public function test_it_versions_readiness_database_guards(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_063700_create_flight_readiness_tables.php'));
        $dcl = file_get_contents(database_path('sql/dcl/009_flight_readiness_grants.sql'));

        $this->assertIsString($migration);
        $this->assertStringContainsString('flight_waypoints_action_check', $migration);
        $this->assertStringContainsString('flight_checklists_type_check', $migration);
        $this->assertStringContainsString('flight_checklists_status_check', $migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString(
            'GRANT SELECT ON TABLE app.flight_waypoints, app.flight_checklists',
            $dcl,
        );
        $this->assertStringContainsString('TO mangroscan_api_rw, mangroscan_report_ro;', $dcl);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $graph = $this->createGraph('constraint-');

        $this->expectException(QueryException::class);
        $this->insertChecklist(
            (string) Str::uuid(),
            $graph['flight_id'],
            $graph['actor_id'],
            'pre_flight',
            'unknown',
            now(),
        );
    }

    /** @return array<string, string> */
    private function createGraph(
        string $prefix = '',
        bool $localPermission = true,
        bool $foreignPermission = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        $roleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $permissionId = (string) Str::uuid();

        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Flight Detail', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Flight Detail', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, $prefix.'flight-detail@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, $prefix.'foreign-flight-detail@example.test');
        DB::table('roles')->insert([
            ['role_id' => $roleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Flight Detail Reader', 'role_code' => $prefix.'flight_detail_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Detail Reader', 'role_code' => $prefix.'foreign_detail_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert([
            'permission_id' => $permissionId,
            'permission_code' => $prefix === '' ? 'flights.read' : $prefix.'flights.read',
            'permission_name' => $prefix.'Read flight detail',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($localPermission || $foreignPermission) {
            $assignedRoleId = $foreignPermission ? $foreignRoleId : $roleId;
            DB::table('role_permissions')->insert(['role_id' => $assignedRoleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $assignedRoleId, 'created_at' => now(), 'updated_at' => now()]);
        }

        $siteId = (string) Str::uuid();
        $foreignSiteId = (string) Str::uuid();
        $this->insertSite($siteId, $organizationId, $actorId, $prefix.'FLT-DETAIL-SITE');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignUserId, $prefix.'FOREIGN-FLT-DETAIL-SITE');
        $missionId = (string) Str::uuid();
        $foreignMissionId = (string) Str::uuid();
        $deletedMissionId = (string) Str::uuid();
        $this->insertMission($missionId, $siteId, $actorId, $prefix.'FLT-DETAIL-MISSION');
        $this->insertMission($foreignMissionId, $foreignSiteId, $foreignUserId, $prefix.'FOREIGN-FLT-DETAIL-MISSION');
        $this->insertMission($deletedMissionId, $siteId, $actorId, $prefix.'DELETED-FLT-DETAIL-MISSION', true);

        $droneId = (string) Str::uuid();
        $foreignDroneId = (string) Str::uuid();
        $this->insertDrone($droneId, $organizationId, $prefix.'Detail Drone', $prefix.'DETAIL-SERIAL');
        $this->insertDrone($foreignDroneId, $foreignOrganizationId, $prefix.'Foreign Detail Drone', $prefix.'FOREIGN-DETAIL-SERIAL');
        $flightId = (string) Str::uuid();
        $foreignFlightId = (string) Str::uuid();
        $deletedMissionFlightId = (string) Str::uuid();
        $this->insertFlight($flightId, $missionId, $droneId, $actorId, $prefix.'FLT-DETAIL', ['type' => 'Point', 'coordinates' => [123.9, 10.2]]);
        $this->insertFlight($foreignFlightId, $foreignMissionId, $foreignDroneId, $foreignUserId, $prefix.'FOREIGN-FLT-DETAIL');
        $this->insertFlight($deletedMissionFlightId, $deletedMissionId, $droneId, $actorId, $prefix.'DELETED-MISSION-FLT-DETAIL');

        $preflightId = (string) Str::uuid();
        $postflightId = (string) Str::uuid();
        $this->insertChecklist($preflightId, $flightId, $actorId, 'pre_flight', 'passed', now()->subMinute());
        $this->insertChecklist($postflightId, $flightId, $actorId, 'post_flight', 'conditional', now());

        for ($sequence = 1; $sequence <= 3; $sequence++) {
            $this->insertWaypoint((string) Str::uuid(), $flightId, $sequence);
        }
        $this->insertWaypoint((string) Str::uuid(), $foreignFlightId, 1);

        return [
            'actor_id' => $actorId,
            'flight_id' => $flightId,
            'foreign_flight_id' => $foreignFlightId,
            'deleted_mission_flight_id' => $deletedMissionFlightId,
            'preflight_id' => $preflightId,
            'postflight_id' => $postflightId,
            'token' => User::query()
                ->findOrFail($actorId)
                ->createToken('Flight detail test', ['*'], now()->addHour())
                ->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id,
            'organization_id' => $organizationId,
            'first_name' => 'Flight',
            'last_name' => 'Detail',
            'email' => $email,
            'password' => Hash::make('password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSite(string $id, string $organizationId, string $creatorId, string $code): void
    {
        DB::table('survey_sites')->insert([
            'site_id' => $id,
            'organization_id' => $organizationId,
            'site_name' => $code,
            'site_code' => $code,
            'province' => 'Negros Oriental',
            'city_municipality' => 'Dumaguete City',
            'environment_type' => 'estuarine',
            'status' => 'active',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMission(string $id, string $siteId, string $creatorId, string $code, bool $deleted = false): void
    {
        DB::table('survey_missions')->insert([
            'mission_id' => $id,
            'site_id' => $siteId,
            'mission_code' => $code,
            'mission_title' => $code,
            'mission_objective' => 'Flight detail',
            'mission_status' => 'planned',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }

    private function insertDrone(string $id, string $organizationId, string $name, string $serial): void
    {
        DB::table('drones')->insert([
            'drone_id' => $id,
            'organization_id' => $organizationId,
            'drone_name' => $name,
            'serial_number' => $serial,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array{type: string, coordinates: array{float, float}}|null $takeoff */
    private function insertFlight(
        string $id,
        string $missionId,
        string $droneId,
        string $pilotId,
        string $code,
        ?array $takeoff = null,
    ): void {
        DB::table('flight_sessions')->insert([
            'flight_session_id' => $id,
            'mission_id' => $missionId,
            'drone_id' => $droneId,
            'pilot_user_id' => $pilotId,
            'flight_code' => $code,
            'flight_status' => 'planned',
            'quality_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($takeoff === null) {
            return;
        }

        $geoJson = json_encode($takeoff, JSON_THROW_ON_ERROR);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'UPDATE flight_sessions SET takeoff_location = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) WHERE flight_session_id = ?',
                [$geoJson, $id],
            );

            return;
        }

        DB::table('flight_sessions')->where('flight_session_id', $id)->update(['takeoff_location' => $geoJson]);
    }

    private function insertChecklist(
        string $id,
        string $flightId,
        string $checkerId,
        string $type,
        string $status,
        mixed $createdAt,
    ): void {
        DB::table('flight_checklists')->insert([
            'checklist_id' => $id,
            'flight_session_id' => $flightId,
            'checked_by' => $checkerId,
            'checklist_type' => $type,
            'battery_ok' => true,
            'weather_ok' => true,
            'gps_ok' => true,
            'camera_ok' => true,
            'lidar_depth_ok' => true,
            'storage_ok' => true,
            'overall_status' => $status,
            'remarks' => 'Readiness evidence',
            'created_at' => $createdAt,
        ]);
    }

    private function insertWaypoint(string $id, string $flightId, int $sequence): void
    {
        $point = json_encode([
            'type' => 'Point',
            'coordinates' => [123.9 + ($sequence / 1000), 10.2],
        ], JSON_THROW_ON_ERROR);
        $values = [
            'waypoint_id' => $id,
            'flight_session_id' => $flightId,
            'sequence_no' => $sequence,
            'action' => 'capture',
            'created_at' => now(),
        ];

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'INSERT INTO flight_waypoints (waypoint_id, flight_session_id, sequence_no, waypoint_location, action, created_at) VALUES (?, ?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?, ?)',
                [$id, $flightId, $sequence, $point, 'capture', now()],
            );

            return;
        }

        DB::table('flight_waypoints')->insert([
            ...$values,
            'waypoint_location' => $point,
        ]);
    }
}
