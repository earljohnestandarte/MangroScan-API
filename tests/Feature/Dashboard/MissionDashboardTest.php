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

class MissionDashboardTest extends TestCase
{
    use RefreshDatabase;

    // [DASH-02] The exact six-group response combines the snapshot with canonical mission details.
    public function test_it_returns_exact_mission_analytics(): void
    {
        $graph = $this->graph();
        $response = $this->withToken($graph['token'])->withHeader('X-Request-ID', 'req_dash_02')
            ->getJson('/api/v1/dashboard/missions/'.$graph['mission']);

        $response->assertOk()->assertHeader('X-Request-ID', 'req_dash_02')->assertJsonCount(1)
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('data.counts.trees', 3)
            ->assertJsonPath('data.counts.validated_trees', 2)
            ->assertJsonPath('data.counts.unvalidated_trees', 1)
            ->assertJsonPath('data.counts.rejected_trees', 0)
            ->assertJsonPath('data.counts.validation_sessions', 1)
            ->assertJsonPath('data.counts.ground_truth_records', 1)
            ->assertJsonPath('data.counts.processing_jobs', 2)
            ->assertJsonPath('data.species.0.scientific_name', 'Rhizophora apiculata')
            ->assertJsonPath('data.species.0.tree_count', 2)
            ->assertJsonPath('data.species.0.percentage', '66.67')
            ->assertJsonPath('data.species.1.scientific_name', 'Avicennia marina')
            ->assertJsonPath('data.species.1.percentage', '33.33')
            ->assertJsonPath('data.height', ['sample_size' => 2, 'minimum' => '6.00', 'maximum' => '10.00', 'average' => '8.00', 'unit' => 'm'])
            ->assertJsonPath('data.age', ['sample_size' => 2, 'minimum' => '4.00', 'maximum' => '8.00', 'average' => '6.00', 'unit' => 'years'])
            ->assertJsonPath('data.accuracy.species_accuracy', '0.950000')
            ->assertJsonPath('data.accuracy.count_f1', '0.750000')
            ->assertJsonPath('data.layers.0.layer_type', 'species_map')
            ->assertJsonMissingPath('data.layers.0.storage_key');
        $this->assertSame(['counts', 'species', 'height', 'age', 'accuracy', 'layers'], array_keys($response->json('data')));
        $this->assertSame(['species_accuracy', 'count_precision', 'count_recall', 'count_f1', 'height_rmse', 'age_mae'], array_keys($response->json('data.accuracy')));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [DASH-02] Empty canonical details are stable and null-safe.
    public function test_it_zero_fills_an_empty_mission(): void
    {
        $graph = $this->graph(evidence: false, prefix: 'empty-');
        $this->withToken($graph['token'])->getJson('/api/v1/dashboard/missions/'.$graph['mission'])
            ->assertOk()->assertJsonPath('data.counts.trees', 0)
            ->assertJsonPath('data.species', [])
            ->assertJsonPath('data.height', ['sample_size' => 0, 'minimum' => null, 'maximum' => null, 'average' => null, 'unit' => 'm'])
            ->assertJsonPath('data.age', ['sample_size' => 0, 'minimum' => null, 'maximum' => null, 'average' => null, 'unit' => 'years'])
            ->assertJsonPath('data.accuracy', [
                'species_accuracy' => null, 'count_precision' => null, 'count_recall' => null,
                'count_f1' => null, 'height_rmse' => null, 'age_mae' => null,
            ])->assertJsonPath('data.layers', []);
    }

    // [DASH-02] The latest metric per type wins independently of insertion order.
    public function test_it_uses_the_latest_accuracy_metric_per_type(): void
    {
        $graph = $this->graph(prefix: 'latest-');
        $oldSession = (string) Str::uuid();
        DB::table('validation_sessions')->insert([
            'validation_session_id' => $oldSession, 'mission_id' => $graph['mission'],
            'site_id' => $graph['site'], 'validated_by' => $graph['actor'],
            'validation_date' => '2026-08-24', 'method' => 'ground_survey', 'status' => 'open',
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        DB::table('accuracy_metrics')->insert([
            'accuracy_metric_id' => (string) Str::uuid(), 'validation_session_id' => $oldSession,
            'mission_id' => $graph['mission'], 'metric_type' => 'species_accuracy', 'metric_value' => 0.10,
            'sample_size' => 1, 'computed_at' => now()->subDay(),
        ]);

        $this->withToken($graph['token'])->getJson('/api/v1/dashboard/missions/'.$graph['mission'])
            ->assertOk()->assertJsonPath('data.accuracy.species_accuracy', '0.950000');
    }

    // [DASH-02] Missing, malformed, foreign, soft-deleted, and operator-hidden missions do not enumerate.
    public function test_it_hides_unavailable_missions(): void
    {
        $graph = $this->graph(prefix: 'scope-');
        foreach (['bad', (string) Str::uuid(), $graph['foreign_mission']] as $id) {
            $this->withToken($graph['token'])->getJson('/api/v1/dashboard/missions/'.$id)->assertNotFound();
        }
        DB::table('survey_missions')->where('mission_id', $graph['mission'])->update(['deleted_at' => now()]);
        $this->withToken($graph['token'])->getJson('/api/v1/dashboard/missions/'.$graph['mission'])->assertNotFound();

        $operator = $this->graph(operator: true, prefix: 'operator-');
        $this->withToken($operator['token'])->getJson('/api/v1/dashboard/missions/'.$operator['mission'])->assertNotFound();
    }

    // [DASH-02] Authentication and local permission are mandatory.
    public function test_it_enforces_access_controls(): void
    {
        $graph = $this->graph(permission: false, prefix: 'access-');
        $path = '/api/v1/dashboard/missions/'.$graph['mission'];
        $this->getJson($path)->assertUnauthorized();
        $this->withToken($graph['token'])->getJson($path)->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'results.read');
    }

    // [DASH-02] Inactive identities are rejected before read-model access.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor'])->update(['status' => 'inactive']);
        $this->withToken($graph['token'])->getJson('/api/v1/dashboard/missions/'.$graph['mission'])->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [DASH-02] Authenticated throttling limits repeated mission analytics reads.
    public function test_it_rate_limits_reads(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->graph(prefix: 'limited-');
        $path = '/api/v1/dashboard/missions/'.$graph['mission'];
        $this->withToken($graph['token'])->getJson($path)->assertOk();
        $this->withToken($graph['token'])->getJson($path)->assertTooManyRequests();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [DASH-02] PostgreSQL exposes an explicitly unavailable snapshot instead of silently mixing freshness.
    public function test_it_reports_a_stale_postgresql_snapshot(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL materialized-view freshness behavior.');
        }
        $graph = $this->graph(evidence: false, prefix: 'stale-');
        $mission = (string) Str::uuid();
        $this->mission($mission, $graph['site'], $graph['actor'], 'STALE-MSN', $graph['actor']);

        $this->withToken($graph['token'])->getJson('/api/v1/dashboard/missions/'.$mission)
            ->assertServiceUnavailable()->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
    }

    // [DASH-02] The endpoint is registered under the existing SELECT-only dashboard DCL.
    public function test_it_registers_the_route_and_read_only_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/dashboard/missions/{mission}'
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
    private function graph(bool $permission = true, bool $operator = false, bool $evidence = true, string $prefix = ''): array
    {
        $ids = [];
        foreach (['organization', 'foreign_organization', 'actor', 'foreign_actor', 'site', 'foreign_site', 'mission', 'foreign_mission', 'species', 'other_species', 'session'] as $key) {
            $ids[$key] = (string) Str::uuid();
        }
        $now = now('UTC');
        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization'], 'organization_name' => $prefix.'Mission Dashboard Org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['organization_id' => $ids['foreign_organization'], 'organization_name' => $prefix.'Foreign Mission Dashboard Org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->user($ids['actor'], $ids['organization'], $prefix.'mission-dashboard@example.test');
        $this->user($ids['foreign_actor'], $ids['foreign_organization'], $prefix.'foreign-mission-dashboard@example.test');
        $this->site($ids['site'], $ids['organization'], $ids['actor'], $prefix.'DASH-SITE');
        $this->site($ids['foreign_site'], $ids['foreign_organization'], $ids['foreign_actor'], $prefix.'FOREIGN-DASH-SITE');
        $this->mission($ids['mission'], $ids['site'], $ids['actor'], $prefix.'DASH-MSN', $operator ? null : $ids['actor']);
        $this->mission($ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor'], $prefix.'FOREIGN-DASH-MSN', $ids['foreign_actor']);
        DB::table('mangrove_species')->insert([
            ['species_id' => $ids['species'], 'scientific_name' => $prefix.'Rhizophora apiculata', 'common_name' => $prefix.'Bakawan lalaki', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['species_id' => $ids['other_species'], 'scientific_name' => $prefix.'Avicennia marina', 'common_name' => $prefix.'Bungalon', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        if ($evidence) {
            $this->evidence($ids);
        }
        $role = (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $ids['organization'], 'role_name' => $prefix.'Mission dashboard role', 'role_code' => $operator ? 'drone_operator' : $prefix.'mission_dashboard_role', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('user_roles')->insert(['user_id' => $ids['actor'], 'role_id' => $role, 'created_at' => $now, 'updated_at' => $now]);
        if ($permission) {
            $permissionId = DB::table('permissions')->where('permission_code', 'results.read')->value('permission_id') ?? (string) Str::uuid();
            DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'results.read', 'permission_name' => 'Read results', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
        }
        app(DashboardReadModelRefresher::class)->refresh(false);
        /** @var User $actor */
        $actor = User::query()->findOrFail($ids['actor']);

        return $ids + ['token' => $actor->createToken($prefix.'mission-dashboard')->plainTextToken];
    }

    /** @param array<string, string> $ids */
    private function evidence(array $ids): void
    {
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $ids['organization'], 'drone_name' => 'Dashboard Drone', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $ids['mission'], 'drone_id' => $drone, 'pilot_user_id' => $ids['actor'], 'flight_code' => 'DASH-FLT-'.substr($ids['mission'], 0, 8), 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([
            [$ids['species'], 'validated', 10, 8], [$ids['species'], 'corrected', 6, 4], [$ids['other_species'], 'unvalidated', null, null],
        ] as $index => [$species, $status, $height, $age]) {
            DB::table('tree_observations')->insert(['tree_observation_id' => (string) Str::uuid(), 'mission_id' => $ids['mission'], 'flight_session_id' => $flight, 'tree_code' => 'DASH-TREE-'.$index, 'tree_location' => $this->point(), 'final_species_id' => $species, 'final_height_meters' => $height, 'final_estimated_age_years' => $age, 'validation_status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('validation_sessions')->insert(['validation_session_id' => $ids['session'], 'mission_id' => $ids['mission'], 'site_id' => $ids['site'], 'validated_by' => $ids['actor'], 'validation_date' => '2026-08-25', 'method' => 'ground_survey', 'status' => 'completed', 'completed_at' => now(), 'completed_by' => $ids['actor'], 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ground_truth_tree_records')->insert(['ground_truth_id' => (string) Str::uuid(), 'validation_session_id' => $ids['session'], 'ground_location' => $this->point(), 'health_status' => 'healthy', 'created_at' => now()]);
        foreach (['queued', 'completed'] as $status) {
            DB::table('processing_jobs')->insert(['processing_job_id' => (string) Str::uuid(), 'mission_id' => $ids['mission'], 'job_type' => 'full_pipeline', 'job_status' => $status, 'started_at' => $status === 'queued' ? null : now()->subMinute(), 'completed_at' => $status === 'completed' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['species_accuracy' => .95, 'count_precision' => .80, 'count_recall' => .70, 'count_f1' => .75, 'height_rmse' => 1.25, 'age_mae' => 2.50] as $type => $value) {
            DB::table('accuracy_metrics')->insert(['accuracy_metric_id' => (string) Str::uuid(), 'validation_session_id' => $ids['session'], 'mission_id' => $ids['mission'], 'metric_type' => $type, 'metric_value' => $value, 'sample_size' => 3, 'computed_at' => now()]);
        }
        DB::table('geospatial_layers')->insert(['layer_id' => (string) Str::uuid(), 'mission_id' => $ids['mission'], 'layer_name' => 'Species Map', 'layer_type' => 'species_map', 'storage_key' => 'private/'.$ids['mission'].'/dashboard-species.geojson', 'style_config' => json_encode(['color' => 'green']), 'is_visible_default' => true, 'created_by' => $ids['actor'], 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(string $id, string $organization, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organization, 'first_name' => 'Dashboard', 'last_name' => 'Viewer', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $organization, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organization, 'site_name' => $code, 'site_code' => $code, 'province' => 'Davao del Sur', 'city_municipality' => 'Davao City', 'environment_type' => 'mangrove', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $site, string $actor, string $code, ?string $approver): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $site, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Mission dashboard analytics.', 'mission_status' => 'completed', 'planned_start_at' => '2026-08-20 08:00:00', 'created_by' => $actor, 'approved_by' => $approver, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function point(): mixed
    {
        return DB::getDriverName() === 'pgsql'
            ? DB::raw('ST_SetSRID(ST_MakePoint(125.60, 7.10), 4326)')
            : json_encode(['type' => 'Point', 'coordinates' => [125.60, 7.10]], JSON_THROW_ON_ERROR);
    }
}
