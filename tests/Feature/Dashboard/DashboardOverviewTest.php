<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Services\Dashboard\DashboardReadModelRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    // [DASH-01] The exact five-group response aggregates only the actor's tenant snapshot.
    public function test_it_returns_the_exact_tenant_dashboard_overview(): void
    {
        $graph = $this->graph();
        $response = $this->withToken($graph['token'])->withHeader('X-Request-ID', 'req_dash_01')
            ->getJson('/api/v1/dashboard/overview');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_dash_01')->assertJsonCount(1)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.missions.total', 2)
            ->assertJsonPath('data.missions.by_status.planned', 1)
            ->assertJsonPath('data.missions.by_status.completed', 1)
            ->assertJsonPath('data.trees.total', 3)
            ->assertJsonPath('data.trees.validated', 1)
            ->assertJsonPath('data.trees.unvalidated', 1)
            ->assertJsonPath('data.trees.rejected', 1)
            ->assertJsonPath('data.species.distinct', 2)
            ->assertJsonPath('data.validation.sessions', 2)
            ->assertJsonPath('data.validation.open_sessions', 1)
            ->assertJsonPath('data.validation.completed_sessions', 1)
            ->assertJsonPath('data.validation.ground_truth_records', 2)
            ->assertJsonPath('data.processing.jobs', 3)
            ->assertJsonPath('data.processing.queued', 1)
            ->assertJsonPath('data.processing.completed', 1)
            ->assertJsonPath('data.processing.failed', 1);
        $this->assertSame(['missions', 'trees', 'species', 'validation', 'processing'], array_keys($response->json('data')));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [DASH-01] Site, mission, and inclusive planned/actual date filters compose.
    public function test_it_applies_composable_dashboard_filters(): void
    {
        $graph = $this->graph(prefix: 'filters-');
        $base = '/api/v1/dashboard/overview?site_id='.$graph['site'].'&mission_id='.$graph['mission'].'&from=2026-08-20&to=2026-08-20';
        $this->withToken($graph['token'])->getJson($base)->assertOk()
            ->assertJsonPath('data.missions.total', 1)
            ->assertJsonPath('data.trees.total', 2)
            ->assertJsonPath('data.species.distinct', 1)
            ->assertJsonPath('data.processing.jobs', 2);

        $this->withToken($graph['token'])->getJson('/api/v1/dashboard/overview?from=2026-09-01')
            ->assertOk()->assertJsonPath('data.missions.total', 0)
            ->assertJsonPath('data.trees.total', 0)->assertJsonPath('data.species.distinct', 0);
    }

    // [DASH-01] Invalid filter shapes fail before a snapshot query.
    public function test_it_validates_dashboard_filters(): void
    {
        $graph = $this->graph(prefix: 'validation-');
        $this->withToken($graph['token'])->getJson('/api/v1/dashboard/overview?site_id=bad&mission_id=bad&from=08-20-2026&to=2026-08-01')
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['site_id', 'mission_id', 'from', 'to'], 'error.details');
    }

    // [DASH-01] Foreign, missing, and inconsistent site/mission filters are non-enumerable.
    public function test_it_hides_unavailable_filter_references(): void
    {
        $graph = $this->graph(prefix: 'scope-');
        foreach ([
            'site_id='.$graph['foreign_site'],
            'mission_id='.$graph['foreign_mission'],
            'site_id='.$graph['other_site'].'&mission_id='.$graph['mission'],
            'site_id='.(string) Str::uuid(),
        ] as $query) {
            $this->withToken($graph['token'])->getJson('/api/v1/dashboard/overview?'.$query)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    // [DASH-01] Authentication and tenant-local results permission are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $graph = $this->graph(permission: false, prefix: 'access-');
        $this->getJson('/api/v1/dashboard/overview')->assertUnauthorized();
        $this->withToken($graph['token'])->getJson('/api/v1/dashboard/overview')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'results.read');
    }

    // [DASH-01] Inactive identities are rejected before read-model access.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor'])->update(['status' => 'inactive']);
        $this->withToken($graph['token'])->getJson('/api/v1/dashboard/overview')
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [DASH-01] An explicitly privileged drone operator sees only assigned approved missions.
    public function test_it_applies_drone_operator_assignment_scope(): void
    {
        $graph = $this->graph(operator: true, prefix: 'operator-');
        DB::table('survey_missions')->where('mission_id', $graph['mission'])->update([
            'approved_by' => $graph['actor'],
        ]);
        DB::table('mission_team_members')->insert([
            'mission_team_id' => (string) Str::uuid(), 'mission_id' => $graph['mission'],
            'user_id' => $graph['actor'], 'team_role' => 'pilot', 'assigned_at' => now(),
        ]);
        app(DashboardReadModelRefresher::class)->refresh(false);

        $this->withToken($graph['token'])->getJson('/api/v1/dashboard/overview')->assertOk()
            ->assertJsonPath('data.missions.total', 1)
            ->assertJsonPath('data.trees.total', 2);
        $this->withToken($graph['token'])->getJson('/api/v1/dashboard/overview?mission_id='.$graph['other_mission'])
            ->assertNotFound();
    }

    // [DASH-01] Authenticated throttling limits repeated dashboard reads.
    public function test_it_rate_limits_dashboard_reads(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->graph(prefix: 'limited-');
        $this->withToken($graph['token'])->getJson('/api/v1/dashboard/overview')->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/dashboard/overview')->assertTooManyRequests();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [DASH-01] The route uses the existing SELECT-only dashboard DCL.
    public function test_it_registers_the_route_and_read_only_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/dashboard/overview'
            && in_array('GET', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:results.read', $route->gatherMiddleware());
        $this->assertContains('throttle:auth.authenticated', $route->gatherMiddleware());
        $dcl = file_get_contents(database_path('sql/dcl/049_dashboard_read_model_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.v_mission_accuracy_summary, app.mv_dashboard_mission_metrics TO mangroscan_api_rw, mangroscan_report_ro;', $dcl);
        foreach (['GRANT INSERT', 'GRANT UPDATE', 'GRANT DELETE'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    /** @return array<string, string> */
    private function graph(bool $permission = true, bool $operator = false, string $prefix = ''): array
    {
        $ids = [];
        foreach (['organization', 'foreign_organization', 'actor', 'foreign_actor', 'site', 'other_site', 'foreign_site', 'mission', 'other_mission', 'foreign_mission', 'species', 'other_species'] as $key) {
            $ids[$key] = (string) Str::uuid();
        }
        $now = now('UTC');
        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization'], 'organization_name' => $prefix.'Dashboard Org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['organization_id' => $ids['foreign_organization'], 'organization_name' => $prefix.'Foreign Dashboard Org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->user($ids['actor'], $ids['organization'], $prefix.'dashboard@example.test');
        $this->user($ids['foreign_actor'], $ids['foreign_organization'], $prefix.'foreign-dashboard@example.test');
        $this->site($ids['site'], $ids['organization'], $ids['actor'], $prefix.'DASH-SITE');
        $this->site($ids['other_site'], $ids['organization'], $ids['actor'], $prefix.'DASH-SITE-2');
        $this->site($ids['foreign_site'], $ids['foreign_organization'], $ids['foreign_actor'], $prefix.'FOREIGN-DASH-SITE');
        $this->mission($ids['mission'], $ids['site'], $ids['actor'], $prefix.'DASH-MSN', 'completed', '2026-08-20');
        $this->mission($ids['other_mission'], $ids['other_site'], $ids['actor'], $prefix.'DASH-MSN-2', 'planned', '2026-08-10');
        $this->mission($ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor'], $prefix.'FOREIGN-DASH-MSN', 'completed', '2026-08-20');
        DB::table('mangrove_species')->insert([
            ['species_id' => $ids['species'], 'scientific_name' => $prefix.'Rhizophora apiculata', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['species_id' => $ids['other_species'], 'scientific_name' => $prefix.'Avicennia marina', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->missionEvidence($ids['mission'], $ids['site'], $ids['actor'], $ids['organization'], $ids['species'], ['validated', 'unvalidated'], 'completed', ['completed', 'failed'], $prefix.'ONE');
        $this->missionEvidence($ids['other_mission'], $ids['other_site'], $ids['actor'], $ids['organization'], $ids['other_species'], ['rejected'], 'open', ['queued'], $prefix.'TWO');
        $this->missionEvidence($ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor'], $ids['foreign_organization'], $ids['species'], ['validated'], 'completed', ['completed'], $prefix.'FOREIGN');

        $role = (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $ids['organization'], 'role_name' => $prefix.'Dashboard role', 'role_code' => $operator ? 'drone_operator' : $prefix.'dashboard_role', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('user_roles')->insert(['user_id' => $ids['actor'], 'role_id' => $role, 'created_at' => $now, 'updated_at' => $now]);
        if ($permission) {
            $permissionId = DB::table('permissions')->where('permission_code', 'results.read')->value('permission_id') ?? (string) Str::uuid();
            DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'results.read', 'permission_name' => 'Read results', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
        }
        app(DashboardReadModelRefresher::class)->refresh(false);
        /** @var User $actor */
        $actor = User::query()->findOrFail($ids['actor']);

        return $ids + ['token' => $actor->createToken($prefix.'dashboard-overview')->plainTextToken];
    }

    private function missionEvidence(string $mission, string $site, string $user, string $organization, string $species, array $treeStatuses, string $sessionStatus, array $jobStatuses, string $code): void
    {
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $organization, 'drone_name' => $code.' Drone', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $user, 'flight_code' => $code.'-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($treeStatuses as $index => $status) {
            DB::table('tree_observations')->insert(['tree_observation_id' => (string) Str::uuid(), 'mission_id' => $mission, 'flight_session_id' => $flight, 'tree_code' => $code.'-TREE-'.$index, 'tree_location' => $this->point(), 'final_species_id' => $species, 'validation_status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
        $session = (string) Str::uuid();
        DB::table('validation_sessions')->insert(['validation_session_id' => $session, 'mission_id' => $mission, 'site_id' => $site, 'validated_by' => $user, 'validation_date' => '2026-08-25', 'method' => 'ground_survey', 'status' => $sessionStatus, 'completed_at' => $sessionStatus === 'completed' ? now() : null, 'completed_by' => $sessionStatus === 'completed' ? $user : null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ground_truth_tree_records')->insert(['ground_truth_id' => (string) Str::uuid(), 'validation_session_id' => $session, 'ground_location' => $this->point(), 'health_status' => 'healthy', 'created_at' => now()]);
        foreach ($jobStatuses as $status) {
            DB::table('processing_jobs')->insert(['processing_job_id' => (string) Str::uuid(), 'mission_id' => $mission, 'job_type' => 'full_pipeline', 'job_status' => $status, 'started_at' => $status === 'queued' ? null : now()->subMinute(), 'completed_at' => in_array($status, ['completed', 'failed'], true) ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function user(string $id, string $organization, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organization, 'first_name' => 'Dashboard', 'last_name' => 'Viewer', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $organization, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organization, 'site_name' => $code, 'site_code' => $code, 'province' => 'Davao del Sur', 'city_municipality' => 'Davao City', 'environment_type' => 'mangrove', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $site, string $actor, string $code, string $status, string $plannedStart): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $site, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Dashboard analytics.', 'mission_status' => $status, 'planned_start_at' => $plannedStart.' 08:00:00', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function point(): mixed
    {
        return DB::getDriverName() === 'pgsql'
            ? DB::raw('ST_SetSRID(ST_MakePoint(125.60, 7.10), 4326)')
            : json_encode(['type' => 'Point', 'coordinates' => [125.60, 7.10]], JSON_THROW_ON_ERROR);
    }
}
