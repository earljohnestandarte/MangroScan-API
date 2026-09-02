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

class ValidationSessionCompleteTest extends TestCase
{
    use RefreshDatabase;

    // [VAL-05] A protocol-ready session completes with the exact resource and audit transition.
    public function test_it_completes_an_audited_validation_session(): void
    {
        $graph = $this->graph();
        $response = $this->withToken($graph['token'])->withHeader('X-Request-ID', 'req_val_05')
            ->postJson($this->uri($graph['session']), ['notes' => ' Field review complete. ']);

        $response->assertOk()->assertHeader('X-Request-ID', 'req_val_05')
            ->assertJsonPath('data.validation_session_id', $graph['session'])
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.notes', 'Field review complete.')
            ->assertJsonPath('data.completed_by', $graph['actor']);
        $this->assertSame([
            'validation_session_id', 'mission_id', 'site_id', 'plot_id', 'validated_by',
            'validation_date', 'method', 'status', 'notes', 'completed_at', 'completed_by',
            'created_at', 'updated_at',
        ], array_keys($response->json('data')));
        $this->assertNotNull($response->json('data.completed_at'));
        $this->assertDatabaseHas('validation_sessions', [
            'validation_session_id' => $graph['session'], 'status' => 'completed',
            'notes' => 'Field review complete.', 'completed_by' => $graph['actor'],
        ]);

        $audit = AuditLog::query()->sole();
        $this->assertSame('validation.complete', $audit->action);
        $this->assertSame($graph['session'], $audit->record_id);
        $this->assertSame('open', $audit->old_values['status']);
        $this->assertSame('completed', $audit->new_values['status']);
        $this->assertSame('req_val_05', $audit->request_id);
    }

    // [VAL-05] Completion requires at least one direct validation decision.
    public function test_it_requires_a_validation_decision(): void
    {
        $graph = $this->graph(decision: false, metrics: false, prefix: 'no-decision-');
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), ['notes' => 'Complete.'])
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        $this->assertOpen($graph['session']);
    }

    // [VAL-05] All six distinct accuracy metrics are required.
    public function test_it_requires_the_complete_metric_set(): void
    {
        $graph = $this->graph(metrics: false, prefix: 'metrics-');
        $this->insertMetrics($graph, ['count_precision', 'count_recall', 'count_f1', 'species_accuracy', 'height_rmse']);
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), ['notes' => 'Complete.'])
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        $this->assertOpen($graph['session']);
    }

    // [VAL-05] Every metric must be recomputed at or after the latest decision.
    public function test_it_rejects_stale_accuracy_metrics(): void
    {
        $graph = $this->graph(prefix: 'stale-');
        DB::table('accuracy_metrics')->where('validation_session_id', $graph['session'])
            ->where('metric_type', 'age_mae')->update(['computed_at' => now()->subMinutes(5)]);
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), ['notes' => 'Complete.'])
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        $this->assertOpen($graph['session']);
    }

    // [VAL-05] Completed sessions cannot be completed twice.
    public function test_it_rejects_a_completed_session(): void
    {
        $graph = $this->graph(prefix: 'closed-');
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), ['notes' => 'Complete.'])->assertOk();
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), ['notes' => 'Again.'])
            ->assertConflict()->assertJsonPath('error.details.status', 'completed');
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [VAL-05] Completion notes are mandatory, trimmed, and bounded.
    public function test_it_validates_completion_notes(): void
    {
        $graph = $this->graph(prefix: 'validation-');
        foreach ([[], ['notes' => '   '], ['notes' => str_repeat('n', 5001)], ['notes' => ['invalid']]] as $payload) {
            $this->withToken($graph['token'])->postJson($this->uri($graph['session']), $payload)
                ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonValidationErrors(['notes'], 'error.details');
        }
        $this->assertOpen($graph['session']);
    }

    // [VAL-05] Foreign, missing, malformed, and cross-lineage sessions remain non-enumerable.
    public function test_it_hides_unavailable_sessions(): void
    {
        $graph = $this->graph(prefix: 'scope-');
        foreach ([$graph['foreign_session'], $graph['inconsistent_session'], (string) Str::uuid(), 'bad'] as $session) {
            $this->withToken($graph['token'])->postJson($this->uri($session), ['notes' => 'Complete.'])
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $this->assertOpen($graph['session']);
    }

    // [VAL-05] Authentication and tenant-local completion permission are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $graph = $this->graph(permission: false, prefix: 'access-');
        $this->postJson($this->uri($graph['session']), ['notes' => 'Complete.'])->assertUnauthorized();
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), ['notes' => 'Complete.'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'validation.complete');
        $this->assertOpen($graph['session']);
    }

    // [VAL-05] Inactive identities cannot complete sessions.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor'])->update(['status' => 'inactive']);
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), ['notes' => 'Complete.'])
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
        $this->assertOpen($graph['session']);
    }

    // [VAL-05] Authenticated throttling prevents a second completion attempt.
    public function test_it_rate_limits_completion(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->graph(prefix: 'limited-');
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), ['notes' => 'Complete.'])->assertOk();
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), ['notes' => 'Again.'])->assertTooManyRequests();
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [VAL-05] Session completion rolls back if mandatory audit persistence fails.
    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $graph = $this->graph(prefix: 'rollback-');
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), ['notes' => 'Complete.'])
            ->assertInternalServerError();
        $this->assertOpen($graph['session']);
    }

    // [VAL-05] Route and corrective column-limited completion grant are versioned.
    public function test_it_registers_the_route_and_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/validation-sessions/{session}/complete'
            && in_array('POST', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:validation.complete', $route->gatherMiddleware());
        $this->assertContains('throttle:auth.authenticated', $route->gatherMiddleware());

        $dcl = file_get_contents(database_path('sql/dcl/053_validation_completion_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('REVOKE UPDATE ON TABLE app.validation_sessions', $dcl);
        $this->assertStringContainsString('GRANT UPDATE (', $dcl);
        foreach (['status', 'notes', 'completed_at', 'completed_by', 'updated_at'] as $column) {
            $this->assertStringContainsString($column, $dcl);
        }
        foreach (['GRANT INSERT', 'GRANT DELETE', 'mangroscan_worker', 'mangroscan_auditor'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    private function assertOpen(string $session): void
    {
        $this->assertDatabaseHas('validation_sessions', [
            'validation_session_id' => $session, 'status' => 'open', 'completed_at' => null, 'completed_by' => null,
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function uri(string $session): string
    {
        return '/api/v1/validation-sessions/'.$session.'/complete';
    }

    /** @return array<string, string> */
    private function graph(
        bool $permission = true,
        bool $decision = true,
        bool $metrics = true,
        string $prefix = '',
    ): array {
        $ids = [];
        foreach (['organization', 'foreign_organization', 'actor', 'foreign_actor', 'site', 'foreign_site', 'mission', 'foreign_mission', 'session', 'foreign_session', 'inconsistent_session', 'truth'] as $key) {
            $ids[$key] = (string) Str::uuid();
        }
        $now = now();
        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization'], 'organization_name' => $prefix.'Completion Org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['organization_id' => $ids['foreign_organization'], 'organization_name' => $prefix.'Foreign Completion Org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->user($ids['actor'], $ids['organization'], $prefix.'completion@example.test');
        $this->user($ids['foreign_actor'], $ids['foreign_organization'], $prefix.'foreign-completion@example.test');
        $this->site($ids['site'], $ids['organization'], $ids['actor'], $prefix.'VAL5-SITE');
        $this->site($ids['foreign_site'], $ids['foreign_organization'], $ids['foreign_actor'], $prefix.'FOREIGN-VAL5-SITE');
        $this->mission($ids['mission'], $ids['site'], $ids['actor'], $prefix.'VAL5-MSN');
        $this->mission($ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor'], $prefix.'FOREIGN-VAL5-MSN');
        $this->validationSession($ids['session'], $ids['mission'], $ids['site'], $ids['actor']);
        $this->validationSession($ids['foreign_session'], $ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor']);
        $this->validationSession($ids['inconsistent_session'], $ids['mission'], $ids['site'], $ids['foreign_actor']);

        if ($decision) {
            DB::table('ground_truth_tree_records')->insert(['ground_truth_id' => $ids['truth'], 'validation_session_id' => $ids['session'], 'ground_location' => $this->point([123.8, 10.1]), 'health_status' => 'healthy', 'created_at' => $now]);
            DB::table('validation_matches')->insert(['validation_match_id' => (string) Str::uuid(), 'validation_session_id' => $ids['session'], 'ground_truth_id' => $ids['truth'], 'tree_observation_id' => null, 'match_status' => 'false_negative', 'validated_by' => $ids['actor'], 'validated_at' => $now->copy()->subMinute()]);
        }
        if ($metrics) {
            $this->insertMetrics($ids, ['count_precision', 'count_recall', 'count_f1', 'species_accuracy', 'height_rmse', 'age_mae']);
        }

        $role = (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $ids['organization'], 'role_name' => $prefix.'Completion role', 'role_code' => $prefix.'completion_role', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('user_roles')->insert(['user_id' => $ids['actor'], 'role_id' => $role, 'created_at' => $now, 'updated_at' => $now]);
        if ($permission) {
            $permissionId = DB::table('permissions')->where('permission_code', 'validation.complete')->value('permission_id') ?? (string) Str::uuid();
            DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'validation.complete', 'permission_name' => 'Complete validation', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
        }
        /** @var User $actor */
        $actor = User::query()->findOrFail($ids['actor']);

        return $ids + ['token' => $actor->createToken($prefix.'completion')->plainTextToken];
    }

    /** @param array<string, string> $graph @param list<string> $types */
    private function insertMetrics(array $graph, array $types): void
    {
        foreach ($types as $type) {
            DB::table('accuracy_metrics')->insert(['accuracy_metric_id' => (string) Str::uuid(), 'validation_session_id' => $graph['session'], 'mission_id' => $graph['mission'], 'metric_type' => $type, 'metric_value' => 1, 'sample_size' => 1, 'computed_at' => now(), 'notes' => 'Ready for completion.']);
        }
    }

    private function user(string $id, string $organization, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organization, 'first_name' => 'Completion', 'last_name' => 'Reviewer', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $organization, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organization, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $site, string $actor, string $code): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $site, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Complete field validation.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
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
