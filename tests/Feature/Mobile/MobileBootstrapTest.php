<?php

namespace Tests\Feature\Mobile;

use App\Models\User;
use App\Services\Mobile\SyncCursorService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileBootstrapTest extends TestCase
{
    use RefreshDatabase;

    // [SYNC-02] Initial bootstrap returns only tenant-visible missions and flights.
    public function test_it_returns_an_authorized_full_snapshot(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-12T12:00:00Z'));
        $graph = $this->createGraph();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$graph['token'],
            'X-Request-ID' => 'req_sync_02_full',
        ])->getJson('/api/v1/mobile/bootstrap');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.missions')
            ->assertJsonCount(1, 'data.flights')
            ->assertJsonPath('data.missions.0.mission_id', $graph['mission_id'])
            ->assertJsonPath('data.flights.0.flight_session_id', $graph['flight_id'])
            ->assertJsonPath('data.checklist_templates', [])
            ->assertJsonPath('data.settings', [])
            ->assertJsonPath('data.tombstones', [])
            ->assertJsonPath('meta.server_time', '2026-08-12T12:00:00+00:00')
            ->assertJsonPath('meta.request_id', 'req_sync_02_full');

        $this->assertIsString($response->json('meta.cursor'));
        $this->assertNotSame('', $response->json('meta.cursor'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [SYNC-02] A cursor returns deterministic changes and tenant tombstones.
    public function test_it_returns_a_delta_after_the_cursor_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-12T12:00:00Z'));
        $graph = $this->createGraph();
        $boundary = CarbonImmutable::parse('2026-08-12T11:00:00Z');
        $cursor = app(SyncCursorService::class)->encode($boundary);
        DB::table('survey_missions')->where('mission_id', $graph['mission_id'])->update([
            'mission_title' => 'Changed Mission',
            'updated_at' => $this->databaseTime('2026-08-12T11:30:00Z'),
        ]);
        DB::table('survey_missions')->where('mission_id', $graph['deleted_mission_id'])->update([
            'deleted_at' => $this->databaseTime('2026-08-12T11:45:00Z'),
        ]);

        $response = $this->withToken($graph['token'])
            ->getJson('/api/v1/mobile/bootstrap?cursor='.urlencode($cursor));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.missions')
            ->assertJsonCount(0, 'data.flights')
            ->assertJsonPath('data.missions.0.mission_title', 'Changed Mission')
            ->assertJsonPath('data.tombstones.0.entity', 'mission')
            ->assertJsonPath('data.tombstones.0.id', $graph['deleted_mission_id']);
        $this->assertTrue(
            CarbonImmutable::parse($response->json('data.tombstones.0.deleted_at'))
                ->equalTo(CarbonImmutable::parse('2026-08-12T11:45:00Z')),
        );
    }

    // [SYNC-02] Opaque cursors reject tampering and future boundaries.
    public function test_it_validates_cursor_integrity_and_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-12T12:00:00Z'));
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->getJson('/api/v1/mobile/bootstrap?cursor=tampered')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor'], 'error.details');

        $future = app(SyncCursorService::class)
            ->encode(CarbonImmutable::parse('2026-08-12T12:01:00Z'));
        $this->withToken($graph['token'])
            ->getJson('/api/v1/mobile/bootstrap?cursor='.urlencode($future))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor'], 'error.details');
    }

    // [SYNC-02] Authentication, both read permissions and active identity are enforced.
    public function test_it_enforces_access(): void
    {
        $graph = $this->createGraph(flightPermission: false);
        $uri = '/api/v1/mobile/bootstrap';

        $this->getJson($uri)->assertUnauthorized();
        $this->withToken($graph['token'])
            ->getJson($uri)
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'flights.read');

        DB::table('organizations')->where('organization_id', $graph['organization_id'])->update([
            'status' => 'inactive',
        ]);
        $this->app->forgetInstance('auth');
        $this->getJson($uri, ['Authorization' => 'Bearer '.$graph['token']])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [SYNC-02] Foreign role grants do not satisfy either tenant permission.
    public function test_it_rejects_foreign_role_permissions(): void
    {
        $graph = $this->createGraph(localPermissions: false, foreignPermissions: true);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/mobile/bootstrap')
            ->assertForbidden();
    }

    // [SYNC-02] Throttling protects repeated bootstrap snapshots.
    public function test_it_rate_limits_bootstrap(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->getJson('/api/v1/mobile/bootstrap')->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/mobile/bootstrap')->assertTooManyRequests();
    }

    /** @return array<string, string> */
    private function createGraph(
        bool $localPermissions = true,
        bool $flightPermission = true,
        bool $foreignPermissions = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => 'Bootstrap Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => 'Foreign Bootstrap Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, 'bootstrap@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, 'foreign-bootstrap@example.test');
        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRoleId, 'organization_id' => $organizationId, 'role_name' => 'Field Reader', 'role_code' => 'field_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => 'Foreign Reader', 'role_code' => 'foreign_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $missionPermissionId = (string) Str::uuid();
        $flightPermissionId = (string) Str::uuid();
        DB::table('permissions')->insert([
            ['permission_id' => $missionPermissionId, 'permission_code' => 'missions.read', 'permission_name' => 'Read missions', 'created_at' => now(), 'updated_at' => now()],
            ['permission_id' => $flightPermissionId, 'permission_code' => 'flights.read', 'permission_name' => 'Read flights', 'created_at' => now(), 'updated_at' => now()],
        ]);
        if ($localPermissions || $foreignPermissions) {
            $roleId = $foreignPermissions ? $foreignRoleId : $localRoleId;
            $grants = [['role_id' => $roleId, 'permission_id' => $missionPermissionId, 'created_at' => now(), 'updated_at' => now()]];
            if ($flightPermission) {
                $grants[] = ['role_id' => $roleId, 'permission_id' => $flightPermissionId, 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('role_permissions')->insert($grants);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        }
        $siteId = (string) Str::uuid();
        $foreignSiteId = (string) Str::uuid();
        $this->insertSite($siteId, $organizationId, $actorId, 'BOOT-SITE');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignUserId, 'FOREIGN-BOOT-SITE');
        $missionId = (string) Str::uuid();
        $foreignMissionId = (string) Str::uuid();
        $deletedMissionId = (string) Str::uuid();
        $this->insertMission($missionId, $siteId, $actorId, 'BOOT-MISSION', '2026-08-12T10:00:00Z');
        $this->insertMission($foreignMissionId, $foreignSiteId, $foreignUserId, 'FOREIGN-BOOT-MISSION', '2026-08-12T10:00:00Z');
        $this->insertMission($deletedMissionId, $siteId, $actorId, 'DELETED-BOOT-MISSION', '2026-08-12T10:00:00Z', true);
        $droneId = (string) Str::uuid();
        $foreignDroneId = (string) Str::uuid();
        $this->insertDrone($droneId, $organizationId, 'BOOT-SERIAL');
        $this->insertDrone($foreignDroneId, $foreignOrganizationId, 'FOREIGN-BOOT-SERIAL');
        $flightId = (string) Str::uuid();
        $this->insertFlight($flightId, $missionId, $droneId, $actorId, 'BOOT-FLIGHT');
        $this->insertFlight((string) Str::uuid(), $foreignMissionId, $foreignDroneId, $foreignUserId, 'FOREIGN-BOOT-FLIGHT');

        return [
            'organization_id' => $organizationId,
            'mission_id' => $missionId,
            'deleted_mission_id' => $deletedMissionId,
            'flight_id' => $flightId,
            'token' => User::query()->findOrFail($actorId)->createToken('Bootstrap test', ['*'], now()->addHour())->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organizationId, 'first_name' => 'Field', 'last_name' => 'User', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertSite(string $id, string $organizationId, string $creatorId, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organizationId, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros', 'city_municipality' => 'Dumaguete', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertMission(string $id, string $siteId, string $creatorId, string $code, string $updatedAt, bool $deleted = false): void
    {
        $databaseTime = $this->databaseTime($updatedAt);
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $siteId, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Offline field work', 'mission_status' => 'planned', 'created_by' => $creatorId, 'created_at' => $databaseTime, 'updated_at' => $databaseTime, 'deleted_at' => $deleted ? $this->databaseTime('2026-08-12T10:00:00Z') : null]);
    }

    private function insertDrone(string $id, string $organizationId, string $serial): void
    {
        DB::table('drones')->insert(['drone_id' => $id, 'organization_id' => $organizationId, 'drone_name' => $serial, 'serial_number' => $serial, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertFlight(string $id, string $missionId, string $droneId, string $pilotId, string $code): void
    {
        $databaseTime = $this->databaseTime('2026-08-12T10:00:00Z');
        DB::table('flight_sessions')->insert(['flight_session_id' => $id, 'mission_id' => $missionId, 'drone_id' => $droneId, 'pilot_user_id' => $pilotId, 'flight_code' => $code, 'flight_status' => 'planned', 'quality_status' => 'pending', 'created_at' => $databaseTime, 'updated_at' => $databaseTime]);
    }

    private function databaseTime(string $value): string
    {
        $time = CarbonImmutable::parse($value)->utc();

        return DB::getDriverName() === 'pgsql'
            ? $time->toIso8601String()
            : $time->format('Y-m-d H:i:s');
    }
}
