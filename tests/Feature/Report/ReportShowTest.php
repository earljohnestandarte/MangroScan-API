<?php

namespace Tests\Feature\Report;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportShowTest extends TestCase
{
    use RefreshDatabase;

    // [RPT-03] Detail combines the exact report draft with live canonical source evidence.
    public function test_it_returns_report_detail_and_source_summary(): void
    {
        $graph = $this->graph();
        $response = $this->withToken($graph['token'])->withHeader('X-Request-ID', 'req_rpt_03')
            ->getJson('/api/v1/reports/'.$graph['report']);

        $response->assertOk()->assertHeader('X-Request-ID', 'req_rpt_03')->assertJsonCount(1)
            ->assertJsonCount(2, 'data')->assertJsonCount(16, 'data.report')
            ->assertJsonPath('data.report.report_id', $graph['report'])
            ->assertJsonPath('data.report.report_status', 'draft')
            ->assertJsonPath('data.report.formats', ['pdf', 'geojson'])
            ->assertJsonPath('data.source_summary.mission.mission_id', $graph['mission'])
            ->assertJsonPath('data.source_summary.mission.mission_status', 'completed')
            ->assertJsonPath('data.source_summary.site.site_id', $graph['site'])
            ->assertJsonPath('data.source_summary.trees.total', 4)
            ->assertJsonPath('data.source_summary.trees.distinct_species', 2)
            ->assertJsonPath('data.source_summary.trees.validated', 2)
            ->assertJsonPath('data.source_summary.trees.unvalidated', 1)
            ->assertJsonPath('data.source_summary.trees.rejected', 1)
            ->assertJsonPath('data.source_summary.validation.sessions', 2)
            ->assertJsonPath('data.source_summary.validation.open_sessions', 1)
            ->assertJsonPath('data.source_summary.validation.completed_sessions', 1)
            ->assertJsonPath('data.source_summary.validation.ground_truth_records', 2)
            ->assertJsonPath('data.source_summary.accuracy.species_accuracy', '0.950000')
            ->assertJsonPath('data.source_summary.accuracy.count_f1', '0.750000');
        $this->assertSame(['report', 'source_summary'], array_keys($response->json('data')));
        $this->assertSame(['mission', 'site', 'trees', 'validation', 'accuracy'], array_keys($response->json('data.source_summary')));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RPT-03] An empty mission remains a complete zero/null source contract.
    public function test_it_returns_a_null_safe_empty_source_summary(): void
    {
        $graph = $this->graph(evidence: false, prefix: 'empty-');
        $this->withToken($graph['token'])->getJson('/api/v1/reports/'.$graph['report'])->assertOk()
            ->assertJsonPath('data.source_summary.trees', [
                'total' => 0, 'distinct_species' => 0, 'validated' => 0, 'unvalidated' => 0, 'rejected' => 0,
            ])->assertJsonPath('data.source_summary.validation', [
                'sessions' => 0, 'open_sessions' => 0, 'completed_sessions' => 0, 'ground_truth_records' => 0,
            ])->assertJsonPath('data.source_summary.accuracy', [
                'species_accuracy' => null, 'count_precision' => null, 'count_recall' => null,
                'count_f1' => null, 'height_rmse' => null, 'age_mae' => null,
            ]);
    }

    // [RPT-03] The source view selects the latest accuracy value for each type.
    public function test_it_uses_latest_accuracy_metrics(): void
    {
        $graph = $this->graph(prefix: 'latest-');
        $session = (string) Str::uuid();
        DB::table('validation_sessions')->insert([
            'validation_session_id' => $session, 'mission_id' => $graph['mission'], 'site_id' => $graph['site'],
            'validated_by' => $graph['actor'], 'validation_date' => '2026-08-24', 'method' => 'ground_survey',
            'status' => 'open', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        DB::table('accuracy_metrics')->insert([
            'accuracy_metric_id' => (string) Str::uuid(), 'validation_session_id' => $session,
            'mission_id' => $graph['mission'], 'metric_type' => 'species_accuracy',
            'metric_value' => .10, 'sample_size' => 1, 'computed_at' => now()->subDay(),
        ]);

        $this->withToken($graph['token'])->getJson('/api/v1/reports/'.$graph['report'])->assertOk()
            ->assertJsonPath('data.source_summary.accuracy.species_accuracy', '0.950000');
    }

    // [RPT-03] Missing, malformed, foreign, inconsistent, and deleted lineage stays non-enumerable.
    public function test_it_hides_unavailable_reports(): void
    {
        $graph = $this->graph(prefix: 'scope-');
        foreach (['bad', (string) Str::uuid(), $graph['foreign_report'], $graph['inconsistent_report']] as $id) {
            $this->withToken($graph['token'])->getJson('/api/v1/reports/'.$id)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        DB::table('survey_missions')->where('mission_id', $graph['mission'])->update(['deleted_at' => now()]);
        $this->withToken($graph['token'])->getJson('/api/v1/reports/'.$graph['report'])->assertNotFound();
    }

    // [RPT-03] Authentication and a current/global reports.read grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $anonymous = $this->graph(prefix: 'anonymous-');
        $this->getJson('/api/v1/reports/'.$anonymous['report'])->assertUnauthorized();

        $missing = $this->graph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->getJson('/api/v1/reports/'.$missing['report'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'reports.read');

        $foreign = $this->graph(foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->getJson('/api/v1/reports/'.$foreign['report'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'reports.read');
    }

    // [RPT-03] Inactive identities are rejected before source-view access.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor'])->update(['status' => 'inactive']);
        $this->withToken($graph['token'])->getJson('/api/v1/reports/'.$graph['report'])
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [RPT-03] Authenticated throttling limits repeated detail reads.
    public function test_it_rate_limits_report_detail_reads(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->graph(prefix: 'limited-');
        $path = '/api/v1/reports/'.$graph['report'];
        $this->withToken($graph['token'])->getJson($path)->assertOk();
        $this->withToken($graph['token'])->getJson($path)->assertTooManyRequests();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RPT-03] V-09 and its route use SELECT-only API/report privileges.
    public function test_it_versions_the_route_source_view_and_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/reports/{report}'
            && in_array('GET', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:reports.read', $route->gatherMiddleware());
        $this->assertContains('throttle:auth.authenticated', $route->gatherMiddleware());
        $migration = file_get_contents(database_path('migrations/2026_08_25_000400_create_report_source_summary_view.php'));
        $dcl = file_get_contents(database_path('sql/dcl/055_report_source_summary_grants.sql'));
        $this->assertIsString($migration);
        foreach (['CREATE VIEW v_report_source_summary AS', 'COUNT(DISTINCT tree.final_species_id)', 'FROM v_mission_accuracy_summary', 'tree.deleted_at IS NULL', 'mission.deleted_at IS NULL', 'site.deleted_at IS NULL'] as $fragment) {
            $this->assertStringContainsString($fragment, $migration);
        }
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.v_report_source_summary', $dcl);
        $this->assertStringContainsString('TO mangroscan_api_rw, mangroscan_report_ro;', $dcl);
        foreach (['GRANT INSERT', 'GRANT UPDATE', 'GRANT DELETE'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    /** @return array<string, string> */
    private function graph(bool $permission = true, bool $foreignPermission = false, bool $evidence = true, string $prefix = ''): array
    {
        $ids = [];
        foreach (['organization', 'foreign_organization', 'actor', 'foreign_actor', 'site', 'foreign_site', 'mission', 'foreign_mission', 'report', 'foreign_report', 'inconsistent_report', 'species', 'other_species', 'session'] as $key) {
            $ids[$key] = (string) Str::uuid();
        }
        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization'], 'organization_name' => $prefix.'Report Detail Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $ids['foreign_organization'], 'organization_name' => $prefix.'Foreign Report Detail Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($ids['actor'], $ids['organization'], $prefix.'report-detail@example.test');
        $this->user($ids['foreign_actor'], $ids['foreign_organization'], $prefix.'foreign-report-detail@example.test');
        $this->site($ids['site'], $ids['organization'], $ids['actor'], $prefix.'RPT-SITE');
        $this->site($ids['foreign_site'], $ids['foreign_organization'], $ids['foreign_actor'], $prefix.'FOREIGN-RPT-SITE');
        $this->mission($ids['mission'], $ids['site'], $ids['actor'], $prefix.'RPT-MSN');
        $this->mission($ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor'], $prefix.'FOREIGN-RPT-MSN');
        DB::table('mangrove_species')->insert([
            ['species_id' => $ids['species'], 'scientific_name' => $prefix.'Rhizophora apiculata', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['species_id' => $ids['other_species'], 'scientific_name' => $prefix.'Avicennia marina', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        if ($evidence) {
            $this->evidence($ids);
        }
        $this->report($ids['report'], $ids['mission'], $ids['site'], $ids['actor']);
        $this->report($ids['foreign_report'], $ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor']);
        $this->report($ids['inconsistent_report'], $ids['foreign_mission'], $ids['site'], $ids['actor']);

        $localRole = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRole, 'organization_id' => $ids['organization'], 'role_name' => $prefix.'Report Reader', 'role_code' => $prefix.'report_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $ids['foreign_organization'], 'role_name' => $prefix.'Foreign Report Reader', 'role_code' => $prefix.'foreign_report_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'reports.read')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'reports.read', 'permission_name' => 'Read reports', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission || $foreignPermission) {
            $role = $foreignPermission ? $foreignRole : $localRole;
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $ids['actor'], 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        /** @var User $actor */
        $actor = User::query()->findOrFail($ids['actor']);

        return $ids + ['token' => $actor->createToken($prefix.'report-show')->plainTextToken];
    }

    /** @param array<string, string> $ids */
    private function evidence(array $ids): void
    {
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $ids['organization'], 'drone_name' => 'Report Drone', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $ids['mission'], 'drone_id' => $drone, 'pilot_user_id' => $ids['actor'], 'flight_code' => 'RPT-FLT-'.substr($ids['mission'], 0, 8), 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([
            [$ids['species'], 'validated'], [$ids['species'], 'corrected'],
            [$ids['other_species'], 'unvalidated'], [$ids['other_species'], 'rejected'],
        ] as $index => [$species, $status]) {
            DB::table('tree_observations')->insert(['tree_observation_id' => (string) Str::uuid(), 'mission_id' => $ids['mission'], 'flight_session_id' => $flight, 'tree_code' => 'RPT-TREE-'.$index, 'tree_location' => $this->point(), 'final_species_id' => $species, 'validation_status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['completed', 'open'] as $index => $status) {
            $session = $index === 0 ? $ids['session'] : (string) Str::uuid();
            DB::table('validation_sessions')->insert(['validation_session_id' => $session, 'mission_id' => $ids['mission'], 'site_id' => $ids['site'], 'validated_by' => $ids['actor'], 'validation_date' => '2026-08-2'.(5 - $index), 'method' => 'ground_survey', 'status' => $status, 'completed_at' => $status === 'completed' ? now() : null, 'completed_by' => $status === 'completed' ? $ids['actor'] : null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ground_truth_tree_records')->insert(['ground_truth_id' => (string) Str::uuid(), 'validation_session_id' => $session, 'ground_location' => $this->point(), 'health_status' => 'healthy', 'created_at' => now()]);
        }
        foreach (['species_accuracy' => .95, 'count_precision' => .80, 'count_recall' => .70, 'count_f1' => .75, 'height_rmse' => 1.25, 'age_mae' => 2.50] as $type => $value) {
            DB::table('accuracy_metrics')->insert(['accuracy_metric_id' => (string) Str::uuid(), 'validation_session_id' => $ids['session'], 'mission_id' => $ids['mission'], 'metric_type' => $type, 'metric_value' => $value, 'sample_size' => 4, 'computed_at' => now()]);
        }
    }

    private function report(string $id, string $mission, string $site, string $actor): void
    {
        DB::table('reports')->insert(['report_id' => $id, 'mission_id' => $mission, 'site_id' => $site, 'report_title' => 'Mangrove Evidence', 'report_type' => 'validation_report', 'report_status' => 'draft', 'audience' => 'Coastal managers', 'summary' => 'Current evidence.', 'formats' => json_encode(['pdf', 'geojson']), 'generated_by' => null, 'approved_by' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(string $id, string $organization, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organization, 'first_name' => 'Report', 'last_name' => 'Reader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $organization, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organization, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $site, string $actor, string $code): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $site, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Prepare report evidence.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function point(): mixed
    {
        return DB::getDriverName() === 'pgsql'
            ? DB::raw('ST_SetSRID(ST_MakePoint(123.30, 9.30), 4326)')
            : json_encode(['type' => 'Point', 'coordinates' => [123.30, 9.30]], JSON_THROW_ON_ERROR);
    }
}
