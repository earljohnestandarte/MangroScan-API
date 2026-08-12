<?php

namespace Tests\Feature\Flight;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MissionFlightIndexTest extends TestCase
{
    use RefreshDatabase;

    // [FLT-01] Sorties are paginated, spatially serialized, and scoped through mission lineage.
    public function test_it_lists_mission_flights_with_exact_safe_fields(): void
    {
        $graph = $this->createGraph();

        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_flt_01_success')
            ->getJson('/api/v1/missions/'.$graph['mission_id'].'/flights?per_page=2&page=1');

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_flt_01_success')
            ->assertJsonPath('meta', [
                'request_id' => 'req_flt_01_success',
                'page' => 1,
                'per_page' => 2,
                'total' => 3,
                'last_page' => 2,
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.flight_session_id', $graph['alpha_flight_id'])
            ->assertJsonPath('data.0.mission_id', $graph['mission_id'])
            ->assertJsonPath('data.0.status', 'planned')
            ->assertJsonPath('data.0.quality_status', 'pending')
            ->assertJsonPath('data.0.takeoff_location.type', 'Point')
            ->assertJsonPath('data.0.takeoff_location.coordinates.0', 123.9)
            ->assertJsonPath('data.0.takeoff_location.coordinates.1', 10.2)
            ->assertJsonPath('data.0.planned_altitude_meters', '80.50');

        $this->assertSame([
            'flight_session_id',
            'mission_id',
            'drone_id',
            'pilot_user_id',
            'flight_code',
            'takeoff_location',
            'landing_location',
            'planned_altitude_meters',
            'actual_avg_altitude_meters',
            'started_at',
            'ended_at',
            'flight_duration_minutes',
            'status',
            'quality_status',
            'notes',
            'created_at',
            'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [FLT-01] Flight and quality lifecycle filters compose after normalization.
    public function test_it_filters_mission_flights(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->getJson('/api/v1/missions/'.$graph['mission_id'].'/flights?status=COMPLETED&quality_status=ACCEPTABLE')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.flight_session_id', $graph['beta_flight_id']);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/missions/'.$graph['mission_id'].'/flights?quality_status=needs_recapture')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.flight_session_id', $graph['gamma_flight_id']);
    }

    // [FLT-01] Invalid lifecycle filters and page bounds fail before mission lookup.
    public function test_it_validates_flight_filters(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_flt_01_validation')
            ->getJson('/api/v1/missions/'.$graph['mission_id'].'/flights?status=active&quality_status=good&page=0&per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.request_id', 'req_flt_01_validation')
            ->assertJsonValidationErrors(['status', 'quality_status', 'page', 'per_page'], 'error.details');
    }

    // [FLT-01] Foreign, deleted, missing, and malformed missions remain non-enumerable.
    public function test_it_hides_unavailable_missions(): void
    {
        $graph = $this->createGraph();

        foreach ([
            $graph['foreign_mission_id'],
            $graph['deleted_mission_id'],
            (string) Str::uuid(),
            'not-a-uuid',
        ] as $missionId) {
            $this->withToken($graph['token'])
                ->getJson('/api/v1/missions/'.$missionId.'/flights')
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    // [FLT-01] Authentication and tenant-valid flights.read permission are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $graph = $this->createGraph(localPermission: false);

        $this->getJson('/api/v1/missions/'.$graph['mission_id'].'/flights')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->withToken($graph['token'])
            ->getJson('/api/v1/missions/'.$graph['mission_id'].'/flights')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'flights.read');
    }

    // [FLT-01] A foreign-organization role cannot authorize current-tenant flight reads.
    public function test_it_rejects_a_foreign_tenant_permission_grant(): void
    {
        $graph = $this->createGraph(localPermission: false, foreignPermission: true);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/missions/'.$graph['mission_id'].'/flights')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'flights.read');
    }

    // [FLT-01] Flight listings share the authenticated request budget.
    public function test_it_rate_limits_flight_lists(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $uri = '/api/v1/missions/'.$graph['mission_id'].'/flights';

        $this->withToken($graph['token'])->getJson($uri)->assertOk();
        $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_flt_01_throttled')
            ->getJson($uri)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_flt_01_throttled');
    }

    // [FLT-01] PostgreSQL guards both documented domains and DCL remains read-only.
    public function test_it_versions_flight_database_guards(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_063600_create_flight_sessions_table.php'));
        $dcl = file_get_contents(database_path('sql/dcl/008_flight_session_grants.sql'));

        $this->assertIsString($migration);
        $this->assertStringContainsString('flight_sessions_status_check', $migration);
        $this->assertStringContainsString('flight_sessions_quality_status_check', $migration);
        $this->assertStringContainsString("'planned', 'flying', 'completed', 'aborted', 'failed'", $migration);
        $this->assertStringContainsString("'pending', 'acceptable', 'rejected', 'needs_recapture'", $migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString(
            'GRANT SELECT ON TABLE app.flight_sessions TO mangroscan_api_rw, mangroscan_report_ro;',
            $dcl,
        );

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $graph = $this->createGraph('constraint-');

        $this->expectException(QueryException::class);
        $this->insertFlight(
            (string) Str::uuid(),
            $graph['mission_id'],
            $graph['drone_id'],
            $graph['actor_id'],
            'CONSTRAINT-INVALID',
            'hovering',
            'pending',
        );
    }

    /**
     * @return array{
     *     actor_id: string,
     *     mission_id: string,
     *     foreign_mission_id: string,
     *     deleted_mission_id: string,
     *     drone_id: string,
     *     alpha_flight_id: string,
     *     beta_flight_id: string,
     *     gamma_flight_id: string,
     *     token: string
     * }
     */
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
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Current Flights', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Flights', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, $prefix.'flight-reader@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, $prefix.'foreign-flight-reader@example.test');
        DB::table('roles')->insert([
            ['role_id' => $roleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Flight Reader', 'role_code' => $prefix.'flight_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Flight Reader', 'role_code' => $prefix.'foreign_flight_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert([
            'permission_id' => $permissionId,
            'permission_code' => $prefix.'flights.read',
            'permission_name' => $prefix.'Read flights',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($localPermission || $foreignPermission) {
            $assignedRoleId = $foreignPermission ? $foreignRoleId : $roleId;
            DB::table('role_permissions')->insert([
                'role_id' => $assignedRoleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('user_roles')->insert([
                'user_id' => $actorId,
                'role_id' => $assignedRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $siteId = (string) Str::uuid();
        $foreignSiteId = (string) Str::uuid();
        $this->insertSite($siteId, $organizationId, $actorId, $prefix.'FLT-SITE');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignUserId, $prefix.'FOREIGN-FLT-SITE');

        $missionId = (string) Str::uuid();
        $foreignMissionId = (string) Str::uuid();
        $deletedMissionId = (string) Str::uuid();
        $this->insertMission($missionId, $siteId, $actorId, $prefix.'FLT-MISSION');
        $this->insertMission($foreignMissionId, $foreignSiteId, $foreignUserId, $prefix.'FOREIGN-FLT-MISSION');
        $this->insertMission($deletedMissionId, $siteId, $actorId, $prefix.'DELETED-FLT-MISSION', true);

        $droneId = (string) Str::uuid();
        $foreignDroneId = (string) Str::uuid();
        $this->insertDrone($droneId, $organizationId, $prefix.'Flight Drone', $prefix.'FLT-SERIAL');
        $this->insertDrone($foreignDroneId, $foreignOrganizationId, $prefix.'Foreign Drone', $prefix.'FOREIGN-FLT-SERIAL');

        $alphaFlightId = (string) Str::uuid();
        $betaFlightId = (string) Str::uuid();
        $gammaFlightId = (string) Str::uuid();
        $this->insertFlight($alphaFlightId, $missionId, $droneId, $actorId, $prefix.'FLT-ALPHA', 'planned', 'pending', ['type' => 'Point', 'coordinates' => [123.9, 10.2]], '80.50');
        $this->insertFlight($betaFlightId, $missionId, $droneId, $actorId, $prefix.'FLT-BETA', 'completed', 'acceptable');
        $this->insertFlight($gammaFlightId, $missionId, $droneId, $actorId, $prefix.'FLT-GAMMA', 'failed', 'needs_recapture');
        $this->insertFlight((string) Str::uuid(), $foreignMissionId, $foreignDroneId, $foreignUserId, $prefix.'FLT-FOREIGN', 'planned', 'pending');
        $this->insertFlight((string) Str::uuid(), $deletedMissionId, $droneId, $actorId, $prefix.'FLT-DELETED-MISSION', 'planned', 'pending');

        return [
            'actor_id' => $actorId,
            'mission_id' => $missionId,
            'foreign_mission_id' => $foreignMissionId,
            'deleted_mission_id' => $deletedMissionId,
            'drone_id' => $droneId,
            'alpha_flight_id' => $alphaFlightId,
            'beta_flight_id' => $betaFlightId,
            'gamma_flight_id' => $gammaFlightId,
            'token' => User::query()
                ->findOrFail($actorId)
                ->createToken('Mission flight list test', ['*'], now()->addHour())
                ->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id,
            'organization_id' => $organizationId,
            'first_name' => 'Flight',
            'last_name' => 'Reader',
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
            'mission_objective' => 'Flight mission objective',
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
        string $status,
        string $qualityStatus,
        ?array $takeoff = null,
        ?string $plannedAltitude = null,
    ): void {
        DB::table('flight_sessions')->insert([
            'flight_session_id' => $id,
            'mission_id' => $missionId,
            'drone_id' => $droneId,
            'pilot_user_id' => $pilotId,
            'flight_code' => $code,
            'planned_altitude_meters' => $plannedAltitude,
            'flight_status' => $status,
            'quality_status' => $qualityStatus,
            'notes' => 'Test sortie',
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

        DB::table('flight_sessions')->where('flight_session_id', $id)->update([
            'takeoff_location' => $geoJson,
        ]);
    }
}
