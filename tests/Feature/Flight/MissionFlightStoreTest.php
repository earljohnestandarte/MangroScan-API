<?php

namespace Tests\Feature\Flight;

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

class MissionFlightStoreTest extends TestCase
{
    use RefreshDatabase;

    // [FLT-02] Creation normalizes a planned sortie and atomically records audit evidence.
    public function test_it_creates_a_flight_for_an_approved_mission(): void
    {
        $graph = $this->createGraph();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$graph['token'],
            'X-Request-ID' => 'req_flt_02_success',
            'User-Agent' => 'Flight Creation Test',
        ])->postJson(
            '/api/v1/missions/'.$graph['mission_id'].'/flights',
            $this->payload($graph),
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.mission_id', $graph['mission_id'])
            ->assertJsonPath('data.drone_id', $graph['drone_id'])
            ->assertJsonPath('data.pilot_user_id', $graph['pilot_id'])
            ->assertJsonPath('data.flight_code', 'FLT-NEW')
            ->assertJsonPath('data.planned_altitude_meters', '85.50')
            ->assertJsonPath('data.status', 'planned')
            ->assertJsonPath('data.quality_status', 'pending')
            ->assertJsonPath('data.notes', 'Coastal route')
            ->assertJsonPath('meta.request_id', 'req_flt_02_success');

        $flightId = $response->json('data.flight_session_id');
        $this->assertDatabaseHas('flight_sessions', [
            'flight_session_id' => $flightId,
            'mission_id' => $graph['mission_id'],
            'flight_code' => 'FLT-NEW',
            'flight_status' => 'planned',
            'quality_status' => 'pending',
        ]);
        $audit = AuditLog::query()->sole();
        $this->assertSame('flight.create', $audit->action);
        $this->assertSame('flight_sessions', $audit->table_name);
        $this->assertSame($flightId, $audit->record_id);
        $this->assertSame('FLT-NEW', $audit->new_values['flight_code']);
        $this->assertSame('req_flt_02_success', $audit->request_id);
    }

    // [FLT-02] Required UUIDs, code uniqueness, numeric scale/range, and types are validated.
    public function test_it_validates_flight_creation(): void
    {
        $graph = $this->createGraph();
        $this->withToken($graph['token'])
            ->postJson('/api/v1/missions/'.$graph['mission_id'].'/flights', $this->payload($graph))
            ->assertCreated();

        $this->withToken($graph['token'])
            ->postJson('/api/v1/missions/'.$graph['mission_id'].'/flights', [
                'drone_id' => 'invalid',
                'pilot_user_id' => 'invalid',
                'flight_code' => ' flt-new ',
                'planned_altitude_meters' => '1.234',
                'notes' => ['invalid'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors([
                'drone_id',
                'pilot_user_id',
                'flight_code',
                'planned_altitude_meters',
                'notes',
            ], 'error.details');

        $this->assertDatabaseCount('flight_sessions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [FLT-02] Only approved missions still in the planned state accept new sorties.
    public function test_it_enforces_the_approved_mission_gate(): void
    {
        $graph = $this->createGraph();

        foreach ([$graph['unapproved_mission_id'], $graph['cancelled_mission_id']] as $index => $missionId) {
            $payload = $this->payload($graph);
            $payload['flight_code'] = 'FLT-CONFLICT-'.$index;

            $this->withToken($graph['token'])
                ->postJson('/api/v1/missions/'.$missionId.'/flights', $payload)
                ->assertConflict()
                ->assertJsonPath('error.code', 'CONFLICT');
        }

        $this->assertDatabaseCount('flight_sessions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [FLT-02] Unavailable drones and inactive pilots are resource-state conflicts.
    public function test_it_rejects_unusable_flight_resources(): void
    {
        $graph = $this->createGraph();

        $maintenance = $this->payload($graph);
        $maintenance['drone_id'] = $graph['maintenance_drone_id'];
        $maintenance['flight_code'] = 'FLT-MAINTENANCE';
        $this->withToken($graph['token'])
            ->postJson('/api/v1/missions/'.$graph['mission_id'].'/flights', $maintenance)
            ->assertConflict()
            ->assertJsonPath('error.details.current_status', 'maintenance');

        $inactive = $this->payload($graph);
        $inactive['pilot_user_id'] = $graph['inactive_pilot_id'];
        $inactive['flight_code'] = 'FLT-INACTIVE-PILOT';
        $this->withToken($graph['token'])
            ->postJson('/api/v1/missions/'.$graph['mission_id'].'/flights', $inactive)
            ->assertConflict()
            ->assertJsonPath('error.details.pilot_user_id', $graph['inactive_pilot_id']);

        $this->assertDatabaseCount('flight_sessions', 0);
    }

    // [FLT-02] Foreign and missing mission, drone, and pilot identifiers remain hidden.
    public function test_it_hides_cross_tenant_resources(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->postJson('/api/v1/missions/'.$graph['foreign_mission_id'].'/flights', $this->payload($graph))
            ->assertNotFound();

        foreach ([
            ['drone_id', $graph['foreign_drone_id']],
            ['drone_id', (string) Str::uuid()],
            ['pilot_user_id', $graph['foreign_pilot_id']],
            ['pilot_user_id', (string) Str::uuid()],
        ] as $index => [$field, $id]) {
            $payload = $this->payload($graph);
            $payload[$field] = $id;
            $payload['flight_code'] = 'FLT-HIDDEN-'.$index;

            $this->withToken($graph['token'])
                ->postJson('/api/v1/missions/'.$graph['mission_id'].'/flights', $payload)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }

        $this->assertDatabaseCount('flight_sessions', 0);
    }

    // [FLT-02] Audit persistence failure rolls back the sortie.
    public function test_it_rolls_back_when_audit_fails(): void
    {
        $graph = $this->createGraph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($graph['token'])
            ->postJson('/api/v1/missions/'.$graph['mission_id'].'/flights', $this->payload($graph))
            ->assertInternalServerError();

        $this->assertDatabaseCount('flight_sessions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [FLT-02] Authentication and current-tenant flights.create are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $graph = $this->createGraph(localPermission: false);
        $uri = '/api/v1/missions/'.$graph['mission_id'].'/flights';

        $this->postJson($uri, $this->payload($graph))->assertUnauthorized();
        $this->withToken($graph['token'])
            ->postJson($uri, $this->payload($graph))
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'flights.create');
    }

    // [FLT-02] Foreign-role grants cannot authorize flight creation.
    public function test_it_rejects_a_foreign_role_permission(): void
    {
        $graph = $this->createGraph(localPermission: false, foreignPermission: true);

        $this->withToken($graph['token'])
            ->postJson(
                '/api/v1/missions/'.$graph['mission_id'].'/flights',
                $this->payload($graph),
            )
            ->assertForbidden();
    }

    // [FLT-02] Throttling prevents a second sortie and audit event.
    public function test_it_rate_limits_flight_creation(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $uri = '/api/v1/missions/'.$graph['mission_id'].'/flights';

        $this->withToken($graph['token'])
            ->postJson($uri, $this->payload($graph))
            ->assertCreated();

        $second = $this->payload($graph);
        $second['flight_code'] = 'FLT-SECOND';
        $this->withToken($graph['token'])
            ->postJson($uri, $second)
            ->assertTooManyRequests();

        $this->assertDatabaseCount('flight_sessions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /** @param array<string, string> $graph @return array<string, mixed> */
    private function payload(array $graph): array
    {
        return [
            'drone_id' => $graph['drone_id'],
            'pilot_user_id' => $graph['pilot_id'],
            'flight_code' => ' flt-new ',
            'planned_altitude_meters' => '85.50',
            'notes' => ' Coastal route ',
        ];
    }

    /** @return array<string, string> */
    private function createGraph(
        bool $localPermission = true,
        bool $foreignPermission = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $pilotId = (string) Str::uuid();
        $inactivePilotId = (string) Str::uuid();
        $foreignPilotId = (string) Str::uuid();
        $roleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $permissionId = (string) Str::uuid();

        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => 'Flight Creation', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => 'Foreign Flight Creation', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, 'flight-creator@example.test');
        $this->insertUser($pilotId, $organizationId, 'pilot@example.test');
        $this->insertUser($inactivePilotId, $organizationId, 'inactive-pilot@example.test', 'inactive');
        $this->insertUser($foreignPilotId, $foreignOrganizationId, 'foreign-pilot@example.test');

        DB::table('roles')->insert([
            ['role_id' => $roleId, 'organization_id' => $organizationId, 'role_name' => 'Flight Creator', 'role_code' => 'flight_creator', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => 'Foreign Flight Creator', 'role_code' => 'foreign_flight_creator', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert([
            'permission_id' => $permissionId,
            'permission_code' => 'flights.create',
            'permission_name' => 'Create flights',
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
        $this->insertSite($siteId, $organizationId, $actorId, 'FLT-CREATE-SITE');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignPilotId, 'FOREIGN-FLT-CREATE-SITE');

        $missionId = (string) Str::uuid();
        $unapprovedMissionId = (string) Str::uuid();
        $cancelledMissionId = (string) Str::uuid();
        $foreignMissionId = (string) Str::uuid();
        $this->insertMission($missionId, $siteId, $actorId, 'FLT-CREATE-MISSION', 'planned', $actorId);
        $this->insertMission($unapprovedMissionId, $siteId, $actorId, 'FLT-UNAPPROVED-MISSION', 'planned');
        $this->insertMission($cancelledMissionId, $siteId, $actorId, 'FLT-CANCELLED-MISSION', 'cancelled');
        $this->insertMission($foreignMissionId, $foreignSiteId, $foreignPilotId, 'FOREIGN-FLT-CREATE-MISSION', 'planned', $foreignPilotId);

        $droneId = (string) Str::uuid();
        $maintenanceDroneId = (string) Str::uuid();
        $foreignDroneId = (string) Str::uuid();
        $this->insertDrone($droneId, $organizationId, 'Available Drone', 'FLT-CREATE-AVAILABLE', 'available');
        $this->insertDrone($maintenanceDroneId, $organizationId, 'Maintenance Drone', 'FLT-CREATE-MAINTENANCE', 'maintenance');
        $this->insertDrone($foreignDroneId, $foreignOrganizationId, 'Foreign Drone', 'FOREIGN-FLT-CREATE', 'available');

        return [
            'actor_id' => $actorId,
            'pilot_id' => $pilotId,
            'inactive_pilot_id' => $inactivePilotId,
            'foreign_pilot_id' => $foreignPilotId,
            'mission_id' => $missionId,
            'unapproved_mission_id' => $unapprovedMissionId,
            'cancelled_mission_id' => $cancelledMissionId,
            'foreign_mission_id' => $foreignMissionId,
            'drone_id' => $droneId,
            'maintenance_drone_id' => $maintenanceDroneId,
            'foreign_drone_id' => $foreignDroneId,
            'token' => User::query()
                ->findOrFail($actorId)
                ->createToken('Flight creation test', ['*'], now()->addHour())
                ->plainTextToken,
        ];
    }

    private function insertUser(
        string $id,
        string $organizationId,
        string $email,
        string $status = 'active',
    ): void {
        DB::table('users')->insert([
            'user_id' => $id,
            'organization_id' => $organizationId,
            'first_name' => 'Flight',
            'last_name' => 'User',
            'email' => $email,
            'password' => Hash::make('password'),
            'status' => $status,
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

    private function insertMission(
        string $id,
        string $siteId,
        string $creatorId,
        string $code,
        string $status,
        ?string $approvedBy = null,
    ): void {
        DB::table('survey_missions')->insert([
            'mission_id' => $id,
            'site_id' => $siteId,
            'mission_code' => $code,
            'mission_title' => $code,
            'mission_objective' => 'Flight planning',
            'mission_status' => $status,
            'created_by' => $creatorId,
            'approved_by' => $approvedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertDrone(
        string $id,
        string $organizationId,
        string $name,
        string $serial,
        string $status,
    ): void {
        DB::table('drones')->insert([
            'drone_id' => $id,
            'organization_id' => $organizationId,
            'drone_name' => $name,
            'serial_number' => $serial,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
