<?php

namespace Tests\Feature\Mobile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileMissionBundleTest extends TestCase
{
    use RefreshDatabase;

    // [SYNC-03] An approved mission bundle contains only its tenant field graph.
    public function test_it_downloads_an_approved_mission_bundle(): void
    {
        $graph = $this->createGraph();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$graph['token'],
            'X-Request-ID' => 'req_sync_03_bundle',
        ])->getJson('/api/v1/mobile/missions/'.$graph['mission_id'].'/bundle');

        $response
            ->assertOk()
            ->assertJsonPath('data.mission.mission_id', $graph['mission_id'])
            ->assertJsonPath('data.site.site_id', $graph['site_id'])
            ->assertJsonPath('data.site.center_point.type', 'Point')
            ->assertJsonPath('data.flights.0.flight_session_id', $graph['flight_id'])
            ->assertJsonPath('data.flights.0.takeoff_location.type', 'Point')
            ->assertJsonPath('data.team.0.mission_team_id', $graph['team_id'])
            ->assertJsonPath('data.boundaries.0.boundary_id', $graph['boundary_id'])
            ->assertJsonPath('data.boundaries.0.boundary_geom.type', 'Polygon')
            ->assertJsonPath('data.plots', [])
            ->assertJsonPath('meta.request_id', 'req_sync_03_bundle')
            ->assertJsonCount(1, 'data.flights')
            ->assertJsonCount(1, 'data.team')
            ->assertJsonCount(1, 'data.boundaries');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [SYNC-03] Approval is mandatory but later mission lifecycle states remain downloadable.
    public function test_it_enforces_approval_without_rejecting_later_states(): void
    {
        $graph = $this->createGraph();
        $uri = '/api/v1/mobile/missions/'.$graph['mission_id'].'/bundle';
        DB::table('survey_missions')->where('mission_id', $graph['mission_id'])->update([
            'approved_by' => null,
        ]);

        $this->withToken($graph['token'])
            ->getJson($uri)
            ->assertNotFound();

        DB::table('survey_missions')->where('mission_id', $graph['mission_id'])->update([
            'approved_by' => $graph['actor_id'],
            'mission_status' => 'completed',
        ]);
        $this->withToken($graph['token'])
            ->getJson($uri)
            ->assertOk()
            ->assertJsonPath('data.mission.status', 'completed');
    }

    // [SYNC-03] Foreign, deleted and missing mission identifiers are indistinguishable.
    public function test_it_hides_unavailable_missions(): void
    {
        $graph = $this->createGraph();

        foreach ([$graph['foreign_mission_id'], $graph['deleted_mission_id'], (string) Str::uuid()] as $missionId) {
            $this->withToken($graph['token'])
                ->getJson('/api/v1/mobile/missions/'.$missionId.'/bundle')
                ->assertNotFound();
        }
    }

    // [SYNC-03] Authentication, active identity and all resource read permissions are mandatory.
    public function test_it_enforces_access(): void
    {
        $graph = $this->createGraph(sitePermission: false);
        $uri = '/api/v1/mobile/missions/'.$graph['mission_id'].'/bundle';

        $this->getJson($uri)->assertUnauthorized();
        $this->withToken($graph['token'])
            ->getJson($uri)
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'sites.read');

        DB::table('organizations')->where('organization_id', $graph['organization_id'])->update(['status' => 'inactive']);
        $this->app->forgetInstance('auth');
        $this->getJson($uri, ['Authorization' => 'Bearer '.$graph['token']])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [SYNC-03] Foreign role grants cannot authorize the bundle.
    public function test_it_rejects_foreign_role_permissions(): void
    {
        $graph = $this->createGraph(localPermissions: false, foreignPermissions: true);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/mobile/missions/'.$graph['mission_id'].'/bundle')
            ->assertForbidden();
    }

    // [SYNC-03] Throttling protects repeated bundle downloads.
    public function test_it_rate_limits_bundle_downloads(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $uri = '/api/v1/mobile/missions/'.$graph['mission_id'].'/bundle';

        $this->withToken($graph['token'])->getJson($uri)->assertOk();
        $this->withToken($graph['token'])->getJson($uri)->assertTooManyRequests();
    }

    /** @return array<string, string> */
    private function createGraph(
        bool $localPermissions = true,
        bool $sitePermission = true,
        bool $foreignPermissions = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => 'Bundle Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => 'Foreign Bundle Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, 'bundle@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, 'foreign-bundle@example.test');
        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRoleId, 'organization_id' => $organizationId, 'role_name' => 'Bundle Reader', 'role_code' => 'bundle_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => 'Foreign Bundle Reader', 'role_code' => 'foreign_bundle_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissions = [];
        foreach (['missions.read', 'flights.read', 'sites.read'] as $code) {
            $id = (string) Str::uuid();
            $permissions[$code] = $id;
            DB::table('permissions')->insert(['permission_id' => $id, 'permission_code' => $code, 'permission_name' => $code, 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($localPermissions || $foreignPermissions) {
            $roleId = $foreignPermissions ? $foreignRoleId : $localRoleId;
            $grants = [];
            foreach ($permissions as $code => $permissionId) {
                if ($code === 'sites.read' && ! $sitePermission) {
                    continue;
                }
                $grants[] = ['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('role_permissions')->insert($grants);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        }
        $siteId = (string) Str::uuid();
        $foreignSiteId = (string) Str::uuid();
        $this->insertSite($siteId, $organizationId, $actorId, 'BUNDLE-SITE');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignUserId, 'FOREIGN-BUNDLE-SITE');
        $missionId = (string) Str::uuid();
        $foreignMissionId = (string) Str::uuid();
        $deletedMissionId = (string) Str::uuid();
        $this->insertMission($missionId, $siteId, $actorId, 'BUNDLE-MISSION');
        $this->insertMission($foreignMissionId, $foreignSiteId, $foreignUserId, 'FOREIGN-BUNDLE-MISSION');
        $this->insertMission($deletedMissionId, $siteId, $actorId, 'DELETED-BUNDLE-MISSION', true);
        $droneId = (string) Str::uuid();
        $foreignDroneId = (string) Str::uuid();
        $this->insertDrone($droneId, $organizationId, 'BUNDLE-SERIAL');
        $this->insertDrone($foreignDroneId, $foreignOrganizationId, 'FOREIGN-BUNDLE-SERIAL');
        $flightId = (string) Str::uuid();
        $this->insertFlight($flightId, $missionId, $droneId, $actorId, 'BUNDLE-FLIGHT');
        $this->insertFlight((string) Str::uuid(), $foreignMissionId, $foreignDroneId, $foreignUserId, 'FOREIGN-BUNDLE-FLIGHT');
        $teamId = (string) Str::uuid();
        DB::table('mission_team_members')->insert(['mission_team_id' => $teamId, 'mission_id' => $missionId, 'user_id' => $actorId, 'team_role' => 'pilot', 'assigned_at' => now()]);
        $boundaryId = (string) Str::uuid();
        $this->insertBoundary($boundaryId, $siteId, $actorId);
        $this->insertBoundary((string) Str::uuid(), $foreignSiteId, $foreignUserId);

        return [
            'organization_id' => $organizationId,
            'actor_id' => $actorId,
            'site_id' => $siteId,
            'mission_id' => $missionId,
            'foreign_mission_id' => $foreignMissionId,
            'deleted_mission_id' => $deletedMissionId,
            'flight_id' => $flightId,
            'team_id' => $teamId,
            'boundary_id' => $boundaryId,
            'token' => User::query()->findOrFail($actorId)->createToken('Bundle test', ['*'], now()->addHour())->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organizationId, 'first_name' => 'Field', 'last_name' => 'User', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertSite(string $id, string $organizationId, string $creatorId, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organizationId, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros', 'city_municipality' => 'Dumaguete', 'center_point' => DB::getDriverName() === 'pgsql' ? null : json_encode(['type' => 'Point', 'coordinates' => [123.9, 10.2]], JSON_THROW_ON_ERROR), 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now()]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('UPDATE survey_sites SET center_point = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) WHERE site_id = ?', [json_encode(['type' => 'Point', 'coordinates' => [123.9, 10.2]], JSON_THROW_ON_ERROR), $id]);
        }
    }

    private function insertMission(string $id, string $siteId, string $actorId, string $code, bool $deleted = false): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $siteId, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Offline mission', 'mission_status' => 'planned', 'created_by' => $actorId, 'approved_by' => $actorId, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }

    private function insertDrone(string $id, string $organizationId, string $serial): void
    {
        DB::table('drones')->insert(['drone_id' => $id, 'organization_id' => $organizationId, 'drone_name' => $serial, 'serial_number' => $serial, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertFlight(string $id, string $missionId, string $droneId, string $pilotId, string $code): void
    {
        DB::table('flight_sessions')->insert(['flight_session_id' => $id, 'mission_id' => $missionId, 'drone_id' => $droneId, 'pilot_user_id' => $pilotId, 'flight_code' => $code, 'flight_status' => 'planned', 'quality_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $point = json_encode(['type' => 'Point', 'coordinates' => [123.9, 10.2]], JSON_THROW_ON_ERROR);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('UPDATE flight_sessions SET takeoff_location = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) WHERE flight_session_id = ?', [$point, $id]);
        } else {
            DB::table('flight_sessions')->where('flight_session_id', $id)->update(['takeoff_location' => $point]);
        }
    }

    private function insertBoundary(string $id, string $siteId, string $creatorId): void
    {
        $polygon = json_encode(['type' => 'Polygon', 'coordinates' => [[[123.8, 10.1], [124.0, 10.1], [124.0, 10.3], [123.8, 10.1]]]], JSON_THROW_ON_ERROR);
        $row = ['boundary_id' => $id, 'site_id' => $siteId, 'boundary_name' => $id, 'boundary_type' => 'management', 'source' => 'field', 'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now()];
        if (DB::getDriverName() === 'pgsql') {
            DB::table('site_boundaries')->insert($row + ['boundary_geom' => DB::raw("ST_SetSRID(ST_GeomFromGeoJSON('{$polygon}'), 4326)")]);
        } else {
            DB::table('site_boundaries')->insert($row + ['boundary_geom' => $polygon]);
        }
    }
}
