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

class FlightCompleteTest extends TestCase
{
    use RefreshDatabase;

    // [FLT-06] A flying sortie completes with its documented landing summary.
    public function test_it_completes_a_flying_flight(): void
    {
        $graph = $this->createGraph();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$graph['token'],
            'X-Request-ID' => 'req_flt_06_success',
            'User-Agent' => 'Flight Complete Test',
        ])->postJson('/api/v1/flights/'.$graph['flight_id'].'/complete', $this->payload());

        $response
            ->assertOk()
            ->assertJsonPath('data.flight_session_id', $graph['flight_id'])
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.landing_location.type', 'Point')
            ->assertJsonPath('data.landing_location.coordinates.0', 123.95)
            ->assertJsonPath('data.landing_location.coordinates.1', 10.25)
            ->assertJsonPath('data.actual_avg_altitude_meters', '42.50')
            ->assertJsonPath('data.flight_duration_minutes', '90.00')
            ->assertJsonPath('data.notes', 'Landing complete')
            ->assertJsonPath('meta.request_id', 'req_flt_06_success');

        $this->assertTrue(
            CarbonImmutable::parse($response->json('data.ended_at'))
                ->equalTo(CarbonImmutable::parse('2026-08-12T10:00:00Z')),
        );
        $audit = AuditLog::query()->sole();
        $this->assertSame('flight.complete', $audit->action);
        $this->assertSame('flying', $audit->old_values['flight_status']);
        $this->assertSame('completed', $audit->new_values['flight_status']);
        $this->assertSame('req_flt_06_success', $audit->request_id);
    }

    // [FLT-06] Omitted optional summary fields preserve existing values.
    public function test_it_completes_with_only_the_required_timestamp(): void
    {
        $graph = $this->createGraph();
        DB::table('flight_sessions')->where('flight_session_id', $graph['flight_id'])->update([
            'actual_avg_altitude_meters' => 38.25,
            'notes' => 'Pilot note',
        ]);

        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/complete', [
                'ended_at' => '2026-08-12T09:00:00Z',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.actual_avg_altitude_meters', '38.25')
            ->assertJsonPath('data.flight_duration_minutes', '30.00')
            ->assertJsonPath('data.notes', 'Pilot note')
            ->assertJsonPath('data.landing_location', null);
    }

    // [FLT-06] Explicit nullable optional values clear stored summary fields.
    public function test_it_accepts_explicit_null_optional_fields(): void
    {
        $graph = $this->createGraph();
        DB::table('flight_sessions')->where('flight_session_id', $graph['flight_id'])->update([
            'actual_avg_altitude_meters' => 38.25,
        ]);

        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/complete', [
                'ended_at' => '2026-08-12T09:00:00Z',
                'landing_location' => null,
                'actual_avg_altitude_meters' => null,
                'notes' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.actual_avg_altitude_meters', null)
            ->assertJsonPath('data.notes', null)
            ->assertJsonPath('data.landing_location', null);

        $audit = AuditLog::query()->sole();
        $this->assertNull($audit->new_values['actual_avg_altitude_meters']);
        $this->assertNull($audit->new_values['notes']);
    }

    // [FLT-06] Only a started flight can complete, and completion follows start.
    public function test_it_enforces_completion_lifecycle_and_time_order(): void
    {
        $graph = $this->createGraph();
        DB::table('flight_sessions')->where('flight_session_id', $graph['flight_id'])->update([
            'flight_status' => 'planned',
            'started_at' => null,
        ]);

        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/complete', $this->payload())
            ->assertConflict()
            ->assertJsonPath('error.details.current_status', 'planned');

        DB::table('flight_sessions')->where('flight_session_id', $graph['flight_id'])->update([
            'flight_status' => 'flying',
            'started_at' => '2026-08-12T08:30:00Z',
        ]);
        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/complete', [
                'ended_at' => '2026-08-12T08:30:00Z',
            ])
            ->assertConflict()
            ->assertJsonPath('error.details.ended_at', '2026-08-12T08:30:00+00:00');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [FLT-06] Timestamp, altitude and Point GeoJSON are strictly validated.
    public function test_it_validates_completion_input(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/complete', [
                'ended_at' => 'invalid',
                'landing_location' => [
                    'type' => 'LineString',
                    'coordinates' => [181, 91, 1],
                ],
                'actual_avg_altitude_meters' => -1.123,
                'notes' => ['invalid'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'ended_at',
                'landing_location.type',
                'landing_location.coordinates',
                'landing_location.coordinates.0',
                'landing_location.coordinates.1',
                'actual_avg_altitude_meters',
                'notes',
            ], 'error.details');
    }

    // [FLT-06] Foreign, deleted-lineage and missing flights remain hidden.
    public function test_it_hides_unavailable_flights(): void
    {
        $graph = $this->createGraph();

        foreach ([$graph['foreign_flight_id'], $graph['deleted_flight_id'], (string) Str::uuid()] as $flightId) {
            $this->withToken($graph['token'])
                ->postJson('/api/v1/flights/'.$flightId.'/complete', $this->payload())
                ->assertNotFound();
        }
    }

    // [FLT-06] Audit failure restores the complete flight state and summary.
    public function test_it_rolls_back_when_audit_fails(): void
    {
        $graph = $this->createGraph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/complete', $this->payload())
            ->assertInternalServerError();

        $flight = DB::table('flight_sessions')->where('flight_session_id', $graph['flight_id'])->first();
        $this->assertSame('flying', $flight->flight_status);
        $this->assertNull($flight->ended_at);
        $this->assertNull($flight->landing_location);
        $this->assertNull($flight->actual_avg_altitude_meters);
        $this->assertNull($flight->flight_duration_minutes);
        $this->assertSame('Existing note', $flight->notes);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [FLT-06] Authentication and tenant-valid flights.complete are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $graph = $this->createGraph(localPermission: false);
        $uri = '/api/v1/flights/'.$graph['flight_id'].'/complete';

        $this->postJson($uri, $this->payload())->assertUnauthorized();
        $this->withToken($graph['token'])
            ->postJson($uri, $this->payload())
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'flights.complete');
    }

    // [FLT-06] A foreign-role permission cannot authorize completion.
    public function test_it_rejects_a_foreign_role_permission(): void
    {
        $graph = $this->createGraph(localPermission: false, foreignPermission: true);

        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/complete', $this->payload())
            ->assertForbidden();
    }

    // [FLT-06] Throttling prevents a repeated transition and audit.
    public function test_it_rate_limits_completion_requests(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $uri = '/api/v1/flights/'.$graph['flight_id'].'/complete';

        $this->withToken($graph['token'])->postJson($uri, $this->payload())->assertOk();
        $this->withToken($graph['token'])->postJson($uri, $this->payload())->assertTooManyRequests();
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'ended_at' => '2026-08-12T10:00:00Z',
            'landing_location' => ['type' => 'Point', 'coordinates' => [123.95, 10.25]],
            'actual_avg_altitude_meters' => 42.5,
            'notes' => '  Landing complete  ',
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
            ['organization_id' => $organizationId, 'organization_name' => 'Complete Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => 'Foreign Complete Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, 'complete@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, 'foreign-complete@example.test');
        DB::table('roles')->insert([
            ['role_id' => $roleId, 'organization_id' => $organizationId, 'role_name' => 'Completer', 'role_code' => 'completer', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => 'Foreign Completer', 'role_code' => 'foreign_completer', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert(['permission_id' => $permissionId, 'permission_code' => 'flights.complete', 'permission_name' => 'Complete flights', 'created_at' => now(), 'updated_at' => now()]);
        if ($localPermission || $foreignPermission) {
            $assignedRoleId = $foreignPermission ? $foreignRoleId : $roleId;
            DB::table('role_permissions')->insert(['role_id' => $assignedRoleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $assignedRoleId, 'created_at' => now(), 'updated_at' => now()]);
        }
        $siteId = (string) Str::uuid();
        $foreignSiteId = (string) Str::uuid();
        $this->insertSite($siteId, $organizationId, $actorId, 'COMPLETE-SITE');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignUserId, 'FOREIGN-COMPLETE-SITE');
        $missionId = (string) Str::uuid();
        $foreignMissionId = (string) Str::uuid();
        $deletedMissionId = (string) Str::uuid();
        $this->insertMission($missionId, $siteId, $actorId, 'COMPLETE-MISSION');
        $this->insertMission($foreignMissionId, $foreignSiteId, $foreignUserId, 'FOREIGN-COMPLETE-MISSION');
        $this->insertMission($deletedMissionId, $siteId, $actorId, 'DELETED-COMPLETE-MISSION', true);
        $droneId = (string) Str::uuid();
        $foreignDroneId = (string) Str::uuid();
        $this->insertDrone($droneId, $organizationId, 'Complete Drone', 'COMPLETE-SERIAL');
        $this->insertDrone($foreignDroneId, $foreignOrganizationId, 'Foreign Complete Drone', 'FOREIGN-COMPLETE-SERIAL');
        $flightId = (string) Str::uuid();
        $foreignFlightId = (string) Str::uuid();
        $deletedFlightId = (string) Str::uuid();
        $this->insertFlight($flightId, $missionId, $droneId, $actorId, 'COMPLETE-FLIGHT');
        $this->insertFlight($foreignFlightId, $foreignMissionId, $foreignDroneId, $foreignUserId, 'FOREIGN-COMPLETE-FLIGHT');
        $this->insertFlight($deletedFlightId, $deletedMissionId, $droneId, $actorId, 'DELETED-COMPLETE-FLIGHT');

        return [
            'flight_id' => $flightId,
            'foreign_flight_id' => $foreignFlightId,
            'deleted_flight_id' => $deletedFlightId,
            'token' => User::query()->findOrFail($actorId)->createToken('Flight complete test', ['*'], now()->addHour())->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organizationId, 'first_name' => 'Flight', 'last_name' => 'Completer', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertSite(string $id, string $organizationId, string $creatorId, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organizationId, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros', 'city_municipality' => 'Dumaguete', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertMission(string $id, string $siteId, string $creatorId, string $code, bool $deleted = false): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $siteId, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Complete flight', 'mission_status' => 'planned', 'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }

    private function insertDrone(string $id, string $organizationId, string $name, string $serial): void
    {
        DB::table('drones')->insert(['drone_id' => $id, 'organization_id' => $organizationId, 'drone_name' => $name, 'serial_number' => $serial, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertFlight(string $id, string $missionId, string $droneId, string $pilotId, string $code): void
    {
        DB::table('flight_sessions')->insert(['flight_session_id' => $id, 'mission_id' => $missionId, 'drone_id' => $droneId, 'pilot_user_id' => $pilotId, 'flight_code' => $code, 'flight_status' => 'flying', 'quality_status' => 'pending', 'started_at' => '2026-08-12T08:30:00Z', 'notes' => 'Existing note', 'created_at' => now(), 'updated_at' => now()]);
    }
}
