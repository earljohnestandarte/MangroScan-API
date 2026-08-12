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

class FlightChecklistStoreTest extends TestCase
{
    use RefreshDatabase;

    // [CHK-01] Submission appends normalized evidence and immutable audit in one transaction.
    public function test_it_submits_a_preflight_checklist(): void
    {
        $graph = $this->createGraph();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$graph['token'],
            'X-Request-ID' => 'req_chk_01_success',
            'User-Agent' => 'Checklist Test',
        ])->postJson(
            '/api/v1/flights/'.$graph['planned_flight_id'].'/checklists',
            $this->payload(),
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.flight_session_id', $graph['planned_flight_id'])
            ->assertJsonPath('data.checked_by', $graph['actor_id'])
            ->assertJsonPath('data.checklist_type', 'pre_flight')
            ->assertJsonPath('data.battery_ok', true)
            ->assertJsonPath('data.weather_ok', true)
            ->assertJsonPath('data.gps_ok', true)
            ->assertJsonPath('data.camera_ok', true)
            ->assertJsonPath('data.lidar_depth_ok', true)
            ->assertJsonPath('data.storage_ok', true)
            ->assertJsonPath('data.overall_status', 'passed')
            ->assertJsonPath('data.remarks', 'Ready to launch')
            ->assertJsonPath('meta.request_id', 'req_chk_01_success');

        $checklistId = $response->json('data.checklist_id');
        $this->assertDatabaseHas('flight_checklists', [
            'checklist_id' => $checklistId,
            'flight_session_id' => $graph['planned_flight_id'],
            'checked_by' => $graph['actor_id'],
            'checklist_type' => 'pre_flight',
            'overall_status' => 'passed',
        ]);
        $audit = AuditLog::query()->sole();
        $this->assertSame('flight.checklist.submit', $audit->action);
        $this->assertSame('flight_checklists', $audit->table_name);
        $this->assertSame($checklistId, $audit->record_id);
        $this->assertSame($graph['planned_flight_id'], $audit->new_values['flight_session_id']);
        $this->assertSame('req_chk_01_success', $audit->request_id);
    }

    // [CHK-01] Repeat submissions append evidence because the current schema defines no uniqueness invariant.
    public function test_it_appends_repeated_checklist_evidence(): void
    {
        $graph = $this->createGraph();
        $uri = '/api/v1/flights/'.$graph['planned_flight_id'].'/checklists';

        $this->withToken($graph['token'])->postJson($uri, $this->payload())->assertCreated();
        $second = $this->payload();
        $second['overall_status'] = 'conditional';
        $second['remarks'] = 'Rechecked after battery swap';
        $this->withToken($graph['token'])->postJson($uri, $second)->assertCreated();

        $this->assertDatabaseCount('flight_checklists', 2);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    // [CHK-01] Required booleans and documented state domains are validated.
    public function test_it_validates_checklist_submission(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['planned_flight_id'].'/checklists', [
                'checklist_type' => 'during_flight',
                'battery_ok' => 'yes',
                'weather_ok' => true,
                'gps_ok' => true,
                'camera_ok' => true,
                'lidar_depth_ok' => true,
                'overall_status' => 'ready',
                'remarks' => ['invalid'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'checklist_type',
                'battery_ok',
                'storage_ok',
                'overall_status',
                'remarks',
            ], 'error.details');

        $this->assertDatabaseCount('flight_checklists', 0);
    }

    // [CHK-01] Pre-flight evidence belongs to planned flights; post-flight evidence to terminal flights.
    public function test_it_enforces_checklist_lifecycle(): void
    {
        $graph = $this->createGraph();

        $postOnPlanned = $this->payload();
        $postOnPlanned['checklist_type'] = 'post_flight';
        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['planned_flight_id'].'/checklists', $postOnPlanned)
            ->assertConflict()
            ->assertJsonPath('error.details.current_status', 'planned');

        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['completed_flight_id'].'/checklists', $this->payload())
            ->assertConflict()
            ->assertJsonPath('error.details.current_status', 'completed');

        $postOnCompleted = $this->payload();
        $postOnCompleted['checklist_type'] = 'post_flight';
        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['completed_flight_id'].'/checklists', $postOnCompleted)
            ->assertCreated();

        $this->assertDatabaseCount('flight_checklists', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [CHK-01] Foreign, deleted-lineage, and missing flight identifiers remain hidden.
    public function test_it_hides_unavailable_flights(): void
    {
        $graph = $this->createGraph();

        foreach ([
            $graph['foreign_flight_id'],
            $graph['deleted_mission_flight_id'],
            (string) Str::uuid(),
        ] as $flightId) {
            $this->withToken($graph['token'])
                ->postJson('/api/v1/flights/'.$flightId.'/checklists', $this->payload())
                ->assertNotFound();
        }

        $this->assertDatabaseCount('flight_checklists', 0);
    }

    // [CHK-01] Audit persistence failure rolls checklist insertion back.
    public function test_it_rolls_back_when_audit_fails(): void
    {
        $graph = $this->createGraph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($graph['token'])
            ->postJson(
                '/api/v1/flights/'.$graph['planned_flight_id'].'/checklists',
                $this->payload(),
            )
            ->assertInternalServerError();

        $this->assertDatabaseCount('flight_checklists', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [CHK-01] Authentication and tenant-valid checklists.submit are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $graph = $this->createGraph(localPermission: false);
        $uri = '/api/v1/flights/'.$graph['planned_flight_id'].'/checklists';

        $this->postJson($uri, $this->payload())->assertUnauthorized();
        $this->withToken($graph['token'])
            ->postJson($uri, $this->payload())
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'checklists.submit');
    }

    // [CHK-01] A foreign-role grant cannot authorize current-tenant evidence.
    public function test_it_rejects_a_foreign_role_permission(): void
    {
        $graph = $this->createGraph(localPermission: false, foreignPermission: true);

        $this->withToken($graph['token'])
            ->postJson(
                '/api/v1/flights/'.$graph['planned_flight_id'].'/checklists',
                $this->payload(),
            )
            ->assertForbidden();
    }

    // [CHK-01] Throttling prevents a second checklist and audit event.
    public function test_it_rate_limits_checklist_submission(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $uri = '/api/v1/flights/'.$graph['planned_flight_id'].'/checklists';

        $this->withToken($graph['token'])->postJson($uri, $this->payload())->assertCreated();
        $this->withToken($graph['token'])->postJson($uri, $this->payload())->assertTooManyRequests();

        $this->assertDatabaseCount('flight_checklists', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'checklist_type' => ' PRE_FLIGHT ',
            'battery_ok' => true,
            'weather_ok' => true,
            'gps_ok' => true,
            'camera_ok' => true,
            'lidar_depth_ok' => true,
            'storage_ok' => true,
            'overall_status' => ' PASSED ',
            'remarks' => ' Ready to launch ',
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
        $foreignUserId = (string) Str::uuid();
        $roleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $permissionId = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => 'Checklist Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => 'Foreign Checklist Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, 'checklist@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, 'foreign-checklist@example.test');
        DB::table('roles')->insert([
            ['role_id' => $roleId, 'organization_id' => $organizationId, 'role_name' => 'Checklist Submitter', 'role_code' => 'checklist_submitter', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => 'Foreign Submitter', 'role_code' => 'foreign_checklist_submitter', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert([
            'permission_id' => $permissionId,
            'permission_code' => 'checklists.submit',
            'permission_name' => 'Submit flight checklists',
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
        $this->insertSite($siteId, $organizationId, $actorId, 'CHK-SITE');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignUserId, 'FOREIGN-CHK-SITE');
        $missionId = (string) Str::uuid();
        $foreignMissionId = (string) Str::uuid();
        $deletedMissionId = (string) Str::uuid();
        $this->insertMission($missionId, $siteId, $actorId, 'CHK-MISSION');
        $this->insertMission($foreignMissionId, $foreignSiteId, $foreignUserId, 'FOREIGN-CHK-MISSION');
        $this->insertMission($deletedMissionId, $siteId, $actorId, 'DELETED-CHK-MISSION', true);
        $droneId = (string) Str::uuid();
        $foreignDroneId = (string) Str::uuid();
        $this->insertDrone($droneId, $organizationId, 'Checklist Drone', 'CHK-SERIAL');
        $this->insertDrone($foreignDroneId, $foreignOrganizationId, 'Foreign Checklist Drone', 'FOREIGN-CHK-SERIAL');
        $plannedFlightId = (string) Str::uuid();
        $completedFlightId = (string) Str::uuid();
        $foreignFlightId = (string) Str::uuid();
        $deletedMissionFlightId = (string) Str::uuid();
        $this->insertFlight($plannedFlightId, $missionId, $droneId, $actorId, 'CHK-PLANNED', 'planned');
        $this->insertFlight($completedFlightId, $missionId, $droneId, $actorId, 'CHK-COMPLETED', 'completed');
        $this->insertFlight($foreignFlightId, $foreignMissionId, $foreignDroneId, $foreignUserId, 'FOREIGN-CHK-FLIGHT', 'planned');
        $this->insertFlight($deletedMissionFlightId, $deletedMissionId, $droneId, $actorId, 'DELETED-CHK-FLIGHT', 'planned');

        return [
            'actor_id' => $actorId,
            'planned_flight_id' => $plannedFlightId,
            'completed_flight_id' => $completedFlightId,
            'foreign_flight_id' => $foreignFlightId,
            'deleted_mission_flight_id' => $deletedMissionFlightId,
            'token' => User::query()
                ->findOrFail($actorId)
                ->createToken('Checklist submission test', ['*'], now()->addHour())
                ->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organizationId, 'first_name' => 'Checklist', 'last_name' => 'User', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertSite(string $id, string $organizationId, string $creatorId, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organizationId, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertMission(string $id, string $siteId, string $creatorId, string $code, bool $deleted = false): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $siteId, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Checklist mission', 'mission_status' => 'planned', 'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }

    private function insertDrone(string $id, string $organizationId, string $name, string $serial): void
    {
        DB::table('drones')->insert(['drone_id' => $id, 'organization_id' => $organizationId, 'drone_name' => $name, 'serial_number' => $serial, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertFlight(string $id, string $missionId, string $droneId, string $pilotId, string $code, string $status): void
    {
        DB::table('flight_sessions')->insert(['flight_session_id' => $id, 'mission_id' => $missionId, 'drone_id' => $droneId, 'pilot_user_id' => $pilotId, 'flight_code' => $code, 'flight_status' => $status, 'quality_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }
}
