<?php

namespace Tests\Feature\Mission;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MissionIndexTest extends TestCase
{
    use RefreshDatabase;

    // [MSN-01] Missions are paginated through current-tenant, non-deleted site lineage.
    public function test_it_lists_only_visible_missions_with_safe_fields(): void
    {
        $graph = $this->createGraph();

        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_msn_01_success')
            ->getJson('/api/v1/missions?per_page=2&page=1');

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_msn_01_success')
            ->assertJsonPath('meta', [
                'request_id' => 'req_msn_01_success',
                'page' => 1,
                'per_page' => 2,
                'total' => 3,
                'last_page' => 2,
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.mission_id', $graph['alpha_mission_id'])
            ->assertJsonPath('data.1.mission_id', $graph['beta_mission_id'])
            ->assertJsonPath('data.0.status', 'planned')
            ->assertJsonPath('data.0.coverage_target_hectares', '15.2500');

        $this->assertSame([
            'mission_id', 'site_id', 'mission_code', 'mission_title', 'mission_objective',
            'planned_start_at', 'planned_end_at', 'actual_start_at', 'actual_end_at',
            'status', 'coverage_target_hectares', 'coverage_completed_hectares',
            'created_by', 'approved_by', 'created_at', 'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [MSN-01] Site, state, date, and search filters compose.
    public function test_it_applies_mission_filters(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->getJson('/api/v1/missions?site_id='.$graph['site_id'].'&status=PLANNED&from=2026-08-09&to=2026-08-11&search=coastal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mission_id', $graph['alpha_mission_id']);
    }

    // [MSN-01] An explicit foreign or missing site filter is hidden with 404.
    public function test_it_hides_unavailable_site_filters(): void
    {
        $graph = $this->createGraph();

        foreach ([$graph['foreign_site_id'], (string) Str::uuid()] as $siteId) {
            $this->withToken($graph['token'])
                ->getJson('/api/v1/missions?site_id='.$siteId)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    // [MSN-01] Invalid states, date ranges, UUIDs, and pages fail before lookup.
    public function test_it_validates_mission_filters(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_msn_01_validation')
            ->getJson('/api/v1/missions?site_id=invalid&status=draft&from=2026-08-12&to=2026-08-11&page=0&per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('error.request_id', 'req_msn_01_validation')
            ->assertJsonValidationErrors(['site_id', 'status', 'to', 'page', 'per_page'], 'error.details');
    }

    // [MSN-01] Authentication and missions.read are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/missions')->assertUnauthorized();

        $graph = $this->createGraph(localPermission: false);
        $this->withToken($graph['token'])
            ->getJson('/api/v1/missions')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'missions.read');
    }

    // [MSN-01] Foreign-role permissions cannot authorize mission reads.
    public function test_it_rejects_a_foreign_tenant_permission_grant(): void
    {
        $graph = $this->createGraph(localPermission: false, foreignPermission: true);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/missions')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'missions.read');
    }

    // [MSN-01] Mission listings share the authenticated request budget.
    public function test_it_rate_limits_mission_lists(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->getJson('/api/v1/missions')->assertOk();
        $this->withToken($graph['token'])
            ->getJson('/api/v1/missions')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    /**
     * @return array{site_id: string, foreign_site_id: string, alpha_mission_id: string, beta_mission_id: string, token: string}
     */
    private function createGraph(bool $localPermission = true, bool $foreignPermission = false): array
    {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        $roleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $permissionId = (string) Str::uuid();
        $siteId = (string) Str::uuid();
        $secondSiteId = (string) Str::uuid();
        $foreignSiteId = (string) Str::uuid();
        $deletedSiteId = (string) Str::uuid();

        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => 'Current', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => 'Foreign', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, 'mission-reader@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, 'foreign-mission-reader@example.test');
        DB::table('roles')->insert([
            ['role_id' => $roleId, 'organization_id' => $organizationId, 'role_name' => 'Mission Reader', 'role_code' => 'mission_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => 'Foreign Mission Reader', 'role_code' => 'foreign_mission_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert(['permission_id' => $permissionId, 'permission_code' => 'missions.read', 'permission_name' => 'Read missions', 'created_at' => now(), 'updated_at' => now()]);

        if ($localPermission || $foreignPermission) {
            $assignedRole = $foreignPermission ? $foreignRoleId : $roleId;
            DB::table('role_permissions')->insert(['role_id' => $assignedRole, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $assignedRole, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->insertSite($siteId, $organizationId, $actorId, 'MISSION-SITE');
        $this->insertSite($secondSiteId, $organizationId, $actorId, 'MISSION-SITE-2');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignUserId, 'MISSION-FOREIGN');
        $this->insertSite($deletedSiteId, $organizationId, $actorId, 'MISSION-DELETED', deleted: true);

        $alphaId = (string) Str::uuid();
        $betaId = (string) Str::uuid();
        $this->insertMission($alphaId, $siteId, $actorId, 'MSN-ALPHA', 'Alpha Coastal Survey', 'Map coastal growth', 'planned', '2026-08-10 08:00:00', '15.2500');
        $this->insertMission($betaId, $secondSiteId, $actorId, 'MSN-BETA', 'Beta Survey', 'Completed survey', 'completed', '2026-08-12 08:00:00');
        $this->insertMission((string) Str::uuid(), $siteId, $actorId, 'MSN-UNSCHEDULED', 'Unscheduled', 'Future work', 'planned');
        $this->insertMission((string) Str::uuid(), $siteId, $actorId, 'MSN-DELETED', 'Deleted', 'Deleted mission', 'planned', '2026-08-09 08:00:00', deleted: true);
        $this->insertMission((string) Str::uuid(), $foreignSiteId, $foreignUserId, 'MSN-FOREIGN', 'Foreign', 'Foreign mission', 'planned', '2026-08-08 08:00:00');
        $this->insertMission((string) Str::uuid(), $deletedSiteId, $actorId, 'MSN-HIDDEN-SITE', 'Hidden Site', 'Hidden mission', 'planned', '2026-08-07 08:00:00');

        return [
            'site_id' => $siteId,
            'foreign_site_id' => $foreignSiteId,
            'alpha_mission_id' => $alphaId,
            'beta_mission_id' => $betaId,
            'token' => User::query()->findOrFail($actorId)->createToken('Mission list test', ['*'], now()->addHour())->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organizationId, 'first_name' => 'Mission', 'last_name' => 'Reader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertSite(string $id, string $organizationId, string $creatorId, string $code, bool $deleted = false): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organizationId, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }

    private function insertMission(string $id, string $siteId, string $creatorId, string $code, string $title, string $objective, string $status, ?string $plannedStart = null, ?string $coverage = null, bool $deleted = false): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $siteId, 'mission_code' => $code, 'mission_title' => $title, 'mission_objective' => $objective, 'planned_start_at' => $plannedStart, 'mission_status' => $status, 'coverage_target_hectares' => $coverage, 'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }
}
