<?php

namespace Tests\Feature\Validation;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AccuracyRecomputeTest extends TestCase
{
    use RefreshDatabase;

    // [ACC-01] Direct session decisions produce the exact six metrics and one rollback-safe audit event.
    public function test_it_recomputes_exact_session_metrics(): void
    {
        $graph = $this->graph();
        $response = $this->withToken($graph['token'])->withHeader('X-Request-ID', 'req_acc_01')
            ->postJson($this->uri($graph['session']));

        $response->assertOk()->assertHeader('X-Request-ID', 'req_acc_01')->assertJsonCount(6, 'data');
        $this->assertSame([
            'age_mae', 'count_f1', 'count_precision', 'count_recall', 'height_rmse', 'species_accuracy',
        ], collect($response->json('data'))->pluck('metric_type')->all());
        foreach ($response->json('data') as $metric) {
            $this->assertSame([
                'accuracy_metric_id', 'validation_session_id', 'mission_id', 'model_version_id',
                'metric_type', 'metric_value', 'sample_size', 'computed_at', 'notes',
            ], array_keys($metric));
            $this->assertSame($graph['session'], $metric['validation_session_id']);
            $this->assertSame($graph['mission'], $metric['mission_id']);
        }
        $byType = collect($response->json('data'))->keyBy('metric_type');
        $this->assertSame('0.500000', $byType['count_precision']['metric_value']);
        $this->assertSame(2, $byType['count_precision']['sample_size']);
        $this->assertSame('0.500000', $byType['count_recall']['metric_value']);
        $this->assertSame(2, $byType['count_recall']['sample_size']);
        $this->assertSame('0.500000', $byType['count_f1']['metric_value']);
        $this->assertSame(3, $byType['count_f1']['sample_size']);
        $this->assertSame('1.000000', $byType['species_accuracy']['metric_value']);
        $this->assertSame('2.000000', $byType['height_rmse']['metric_value']);
        $this->assertSame('3.000000', $byType['age_mae']['metric_value']);
        $this->assertDatabaseCount('accuracy_metrics', 6);

        $audit = AuditLog::query()->sole();
        $this->assertSame('accuracy.recompute', $audit->action);
        $this->assertSame($graph['session'], $audit->record_id);
        $this->assertSame(3, $audit->new_values['decision_count']);
        $this->assertSame('req_acc_01', $audit->request_id);
    }

    // [ACC-01] Recompute upserts the same six metric identities instead of accumulating stale rows.
    public function test_it_replaces_session_metrics_idempotently(): void
    {
        $graph = $this->graph(prefix: 'repeat-');
        $first = $this->withToken($graph['token'])->postJson($this->uri($graph['session']))->assertOk();
        $firstIds = collect($first->json('data'))->pluck('accuracy_metric_id', 'metric_type');

        DB::table('validation_matches')->where('validation_session_id', $graph['session'])
            ->where('match_status', 'false_positive')->delete();
        $second = $this->withToken($graph['token'])->postJson($this->uri($graph['session']))->assertOk();
        $secondIds = collect($second->json('data'))->pluck('accuracy_metric_id', 'metric_type');

        $this->assertSame($firstIds->all(), $secondIds->all());
        $this->assertSame('1.000000', collect($second->json('data'))->keyBy('metric_type')['count_precision']['metric_value']);
        $this->assertDatabaseCount('accuracy_metrics', 6);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    // [ACC-01] An open session needs at least one direct validation decision.
    public function test_it_requires_a_validation_decision(): void
    {
        $graph = $this->graph(decisions: false, prefix: 'empty-');
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']))
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        $this->assertDatabaseCount('accuracy_metrics', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [ACC-01] Completed sessions are immutable.
    public function test_it_rejects_a_completed_session(): void
    {
        $graph = $this->graph(prefix: 'closed-');
        DB::table('validation_sessions')->where('validation_session_id', $graph['session'])->update(['status' => 'completed']);
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']))
            ->assertConflict()->assertJsonPath('error.details.status', 'completed');
        $this->assertDatabaseCount('accuracy_metrics', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [ACC-01] Foreign, missing, malformed, and cross-lineage sessions remain non-enumerable.
    public function test_it_hides_unavailable_sessions(): void
    {
        $graph = $this->graph(prefix: 'scope-');
        foreach ([$graph['foreign_session'], $graph['inconsistent_session'], (string) Str::uuid(), 'bad'] as $session) {
            $this->withToken($graph['token'])->postJson($this->uri($session))
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $this->assertDatabaseCount('accuracy_metrics', 0);
    }

    // [ACC-01] Authentication and tenant-local accuracy permission are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $graph = $this->graph(permission: false, prefix: 'access-');
        $this->postJson($this->uri($graph['session']))->assertUnauthorized();
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']))
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'accuracy.recompute');
    }

    // [ACC-01] Inactive identities are rejected before metric writes.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor'])->update(['status' => 'inactive']);
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']))
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
        $this->assertDatabaseCount('accuracy_metrics', 0);
    }

    // [ACC-01] Authenticated throttling prevents the second recompute and audit.
    public function test_it_rate_limits_recomputation(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->graph(prefix: 'limited-');
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']))->assertOk();
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']))->assertTooManyRequests();
        $this->assertDatabaseCount('accuracy_metrics', 6);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [ACC-01] Metric upserts roll back if mandatory audit persistence fails.
    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $graph = $this->graph(prefix: 'rollback-');
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']))->assertInternalServerError();
        $this->assertDatabaseCount('accuracy_metrics', 0);
    }

    // [ACC-01] Route and corrective least-privilege accuracy grants are versioned.
    public function test_it_registers_the_route_and_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/validation-sessions/{session}/accuracy/recompute'
            && in_array('POST', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:accuracy.recompute', $route->gatherMiddleware());
        $this->assertContains('throttle:auth.authenticated', $route->gatherMiddleware());

        $dcl = file_get_contents(database_path('sql/dcl/052_accuracy_recompute_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('REVOKE INSERT, UPDATE, DELETE ON TABLE app.accuracy_metrics', $dcl);
        $this->assertStringContainsString('GRANT INSERT (', $dcl);
        $this->assertStringContainsString('GRANT UPDATE (', $dcl);
        foreach (['mangroscan_worker', 'mangroscan_report_ro', 'mangroscan_auditor'] as $role) {
            $this->assertStringNotContainsString($role, $dcl);
        }
    }

    private function uri(string $session): string
    {
        return '/api/v1/validation-sessions/'.$session.'/accuracy/recompute';
    }

    /** @return array<string, string> */
    private function graph(bool $permission = true, bool $decisions = true, string $prefix = ''): array
    {
        $ids = [];
        foreach (['organization', 'foreign_organization', 'actor', 'foreign_actor', 'site', 'foreign_site', 'mission', 'foreign_mission', 'session', 'foreign_session', 'inconsistent_session', 'drone', 'flight', 'tree', 'other_tree', 'truth', 'other_truth'] as $key) {
            $ids[$key] = (string) Str::uuid();
        }
        $now = now();
        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization'], 'organization_name' => $prefix.'Accuracy Org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['organization_id' => $ids['foreign_organization'], 'organization_name' => $prefix.'Foreign Accuracy Org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->user($ids['actor'], $ids['organization'], $prefix.'accuracy@example.test');
        $this->user($ids['foreign_actor'], $ids['foreign_organization'], $prefix.'foreign-accuracy@example.test');
        $this->site($ids['site'], $ids['organization'], $ids['actor'], $prefix.'ACC-SITE');
        $this->site($ids['foreign_site'], $ids['foreign_organization'], $ids['foreign_actor'], $prefix.'FOREIGN-ACC-SITE');
        $this->mission($ids['mission'], $ids['site'], $ids['actor'], $prefix.'ACC-MSN');
        $this->mission($ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor'], $prefix.'FOREIGN-ACC-MSN');
        $this->validationSession($ids['session'], $ids['mission'], $ids['site'], $ids['actor']);
        $this->validationSession($ids['foreign_session'], $ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor']);
        $this->validationSession($ids['inconsistent_session'], $ids['mission'], $ids['site'], $ids['foreign_actor']);

        if ($decisions) {
            DB::table('drones')->insert(['drone_id' => $ids['drone'], 'organization_id' => $ids['organization'], 'drone_name' => $prefix.'Accuracy Drone', 'status' => 'available', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('flight_sessions')->insert(['flight_session_id' => $ids['flight'], 'mission_id' => $ids['mission'], 'drone_id' => $ids['drone'], 'pilot_user_id' => $ids['actor'], 'flight_code' => $prefix.'ACC-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => $now, 'updated_at' => $now]);
            foreach ([[$ids['tree'], $prefix.'ACC-TREE'], [$ids['other_tree'], $prefix.'ACC-TREE-2']] as [$tree, $code]) {
                DB::table('tree_observations')->insert(['tree_observation_id' => $tree, 'mission_id' => $ids['mission'], 'flight_session_id' => $ids['flight'], 'tree_code' => $code, 'tree_location' => $this->point([123.8, 10.1]), 'validation_status' => 'unvalidated', 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach ([$ids['truth'], $ids['other_truth']] as $truth) {
                DB::table('ground_truth_tree_records')->insert(['ground_truth_id' => $truth, 'validation_session_id' => $ids['session'], 'ground_location' => $this->point([123.8001, 10.1001]), 'health_status' => 'healthy', 'created_at' => $now]);
            }
            DB::table('validation_matches')->insert([
                ['validation_match_id' => (string) Str::uuid(), 'validation_session_id' => $ids['session'], 'ground_truth_id' => $ids['truth'], 'tree_observation_id' => $ids['tree'], 'match_status' => 'matched', 'species_correct' => true, 'height_error_meters' => 2, 'age_error_years' => 3, 'validated_by' => $ids['actor'], 'validated_at' => $now],
                ['validation_match_id' => (string) Str::uuid(), 'validation_session_id' => $ids['session'], 'ground_truth_id' => null, 'tree_observation_id' => $ids['other_tree'], 'match_status' => 'false_positive', 'species_correct' => null, 'height_error_meters' => null, 'age_error_years' => null, 'validated_by' => $ids['actor'], 'validated_at' => $now],
                ['validation_match_id' => (string) Str::uuid(), 'validation_session_id' => $ids['session'], 'ground_truth_id' => $ids['other_truth'], 'tree_observation_id' => null, 'match_status' => 'false_negative', 'species_correct' => null, 'height_error_meters' => null, 'age_error_years' => null, 'validated_by' => $ids['actor'], 'validated_at' => $now],
            ]);
        }

        $role = (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $ids['organization'], 'role_name' => $prefix.'Accuracy role', 'role_code' => $prefix.'accuracy_role', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('user_roles')->insert(['user_id' => $ids['actor'], 'role_id' => $role, 'created_at' => $now, 'updated_at' => $now]);
        if ($permission) {
            $permissionId = DB::table('permissions')->where('permission_code', 'accuracy.recompute')->value('permission_id') ?? (string) Str::uuid();
            DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'accuracy.recompute', 'permission_name' => 'Recompute accuracy', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
        }
        /** @var User $actor */
        $actor = User::query()->findOrFail($ids['actor']);

        return $ids + ['token' => $actor->createToken($prefix.'accuracy')->plainTextToken];
    }

    private function user(string $id, string $organization, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organization, 'first_name' => 'Accuracy', 'last_name' => 'Reviewer', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $organization, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organization, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $site, string $actor, string $code): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $site, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Compute accuracy.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function validationSession(string $id, string $mission, string $site, string $actor): void
    {
        DB::table('validation_sessions')->insert(['validation_session_id' => $id, 'mission_id' => $mission, 'site_id' => $site, 'validated_by' => $actor, 'validation_date' => '2026-08-25', 'method' => 'ground_survey', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array{0:float,1:float} $coordinates */
    private function point(array $coordinates): mixed
    {
        $json = json_encode(['type' => 'Point', 'coordinates' => $coordinates], JSON_THROW_ON_ERROR);

        return DB::getDriverName() === 'pgsql'
            ? DB::raw("ST_SetSRID(ST_GeomFromGeoJSON('$json'), 4326)")
            : $json;
    }
}
