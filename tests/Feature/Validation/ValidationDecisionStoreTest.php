<?php

namespace Tests\Feature\Validation;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ValidationDecisionStoreTest extends TestCase
{
    use RefreshDatabase;

    // [MATCH-01] Matched decisions preserve server-derived error evidence and validate the tree.
    public function test_it_creates_an_audited_matched_decision(): void
    {
        $graph = $this->graph();
        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_match_01')
            ->postJson($this->uri($graph['session']), [
                'tree_observation_id' => strtoupper($graph['tree']),
                'ground_truth_id' => strtoupper($graph['truth']),
                'decision' => ' MATCHED ',
                'notes' => ' Confirmed pair. ',
                'validation_evidence' => ['reviewer' => 'field-team', 'confidence' => 0.99],
            ]);

        $response->assertCreated()->assertHeader('X-Request-ID', 'req_match_01')
            ->assertJsonPath('data.validation_session_id', $graph['session'])
            ->assertJsonPath('data.ground_truth_id', $graph['truth'])
            ->assertJsonPath('data.tree_observation_id', $graph['tree'])
            ->assertJsonPath('data.match_status', 'matched')
            ->assertJsonPath('data.species_correct', true)
            ->assertJsonPath('data.height_error_meters', '2.0000')
            ->assertJsonPath('data.age_error_years', '2.0000')
            ->assertJsonPath('data.notes', 'Confirmed pair.')
            ->assertJsonPath('data.validation_evidence.reviewer', 'field-team')
            ->assertJsonPath('data.corrected_geometry', null);

        $this->assertSame([
            'validation_match_id', 'validation_session_id', 'ground_truth_id', 'tree_observation_id',
            'match_status', 'accepted_species_id', 'accepted_height_m', 'accepted_age_years',
            'corrected_geometry', 'notes', 'validation_evidence', 'distance_error_meters',
            'species_correct', 'height_error_meters', 'age_error_years', 'validated_by', 'validated_at',
        ], array_keys($response->json('data')));
        $this->assertGreaterThan(0, (float) $response->json('data.distance_error_meters'));
        $this->assertDatabaseHas('tree_observations', [
            'tree_observation_id' => $graph['tree'], 'validation_status' => 'validated',
            'final_height_meters' => 10, 'final_estimated_age_years' => 8,
        ]);

        $audit = AuditLog::query()->sole();
        $this->assertSame('validation.decision.create', $audit->action);
        $this->assertSame($response->json('data.validation_match_id'), $audit->record_id);
        $this->assertSame('req_match_01', $audit->request_id);
        $this->assertSame('field-team', $audit->new_values['validation_evidence']['reviewer']);
    }

    // [MATCH-01] Corrected decisions update only supplied canonical values after measuring the original error.
    public function test_it_applies_a_corrected_decision_and_preserves_evidence(): void
    {
        $graph = $this->graph(prefix: 'corrected-');
        $corrected = ['type' => 'Point', 'coordinates' => [123.801, 10.101]];

        $response = $this->withToken($graph['token'])->postJson($this->uri($graph['session']), [
            'tree_observation_id' => $graph['tree'],
            'ground_truth_id' => $graph['truth'],
            'decision' => 'corrected',
            'accepted_species_id' => $graph['other_species'],
            'accepted_height_m' => 7,
            'accepted_age_years' => 5,
            'corrected_geometry' => $corrected,
            'notes' => 'Canonical values corrected.',
            'validation_evidence' => ['photos' => 2],
        ])->assertCreated()
            ->assertJsonPath('data.match_status', 'corrected')
            ->assertJsonPath('data.accepted_species_id', $graph['other_species'])
            ->assertJsonPath('data.accepted_height_m', '7.00')
            ->assertJsonPath('data.accepted_age_years', '5.00')
            ->assertJsonPath('data.corrected_geometry', $corrected)
            ->assertJsonPath('data.species_correct', false)
            ->assertJsonPath('data.height_error_meters', '3.0000')
            ->assertJsonPath('data.age_error_years', '3.0000');

        $tree = DB::table('tree_observations')->where('tree_observation_id', $graph['tree'])->first();
        $this->assertSame('corrected', $tree->validation_status);
        $this->assertSame($graph['other_species'], $tree->final_species_id);
        $this->assertSame('7', rtrim(rtrim((string) $tree->final_height_meters, '0'), '.'));
        $this->assertSame('5', rtrim(rtrim((string) $tree->final_estimated_age_years, '0'), '.'));
        $storedGeometry = $this->treeGeometry($graph['tree']);
        $this->assertSame($corrected, $storedGeometry);
        $this->assertGreaterThan(0, (float) $response->json('data.distance_error_meters'));
    }

    // [MATCH-01] False decisions use the approved asymmetric references and feed accuracy directly by session.
    public function test_false_positive_and_false_negative_decisions_preserve_real_lineage(): void
    {
        $graph = $this->graph(permissions: ['validation.decide', 'accuracy.recompute'], prefix: 'false-');

        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), [
            'tree_observation_id' => $graph['tree'],
            'decision' => 'false_positive',
            'notes' => 'Detection is not a tree.',
        ])->assertCreated()
            ->assertJsonPath('data.ground_truth_id', null)
            ->assertJsonPath('data.match_status', 'false_positive')
            ->assertJsonPath('data.distance_error_meters', null);

        $treeCount = DB::table('tree_observations')->count();
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), [
            'ground_truth_id' => $graph['other_truth'],
            'decision' => 'false_negative',
            'validation_evidence' => ['reason' => 'occluded'],
        ])->assertCreated()
            ->assertJsonPath('data.tree_observation_id', null)
            ->assertJsonPath('data.match_status', 'false_negative')
            ->assertJsonPath('data.species_correct', null);

        $this->assertDatabaseHas('tree_observations', [
            'tree_observation_id' => $graph['tree'], 'validation_status' => 'rejected',
        ]);
        $this->assertSame($treeCount, DB::table('tree_observations')->count());
        $this->assertDatabaseCount('validation_matches', 2);

        $metrics = $this->withToken($graph['token'])
            ->postJson('/api/v1/validation-sessions/'.$graph['session'].'/accuracy/recompute')
            ->assertOk()->json('data');
        $byType = collect($metrics)->keyBy('metric_type');
        $this->assertSame('0.000000', $byType['count_precision']['metric_value']);
        $this->assertSame(1, $byType['count_precision']['sample_size']);
        $this->assertSame('0.000000', $byType['count_recall']['metric_value']);
        $this->assertSame(1, $byType['count_recall']['sample_size']);
    }

    // [MATCH-01] Decision references, correction payloads, geometry, and evidence are strict.
    public function test_it_validates_decision_specific_contracts(): void
    {
        $graph = $this->graph();
        $cases = [
            ['payload' => ['decision' => 'matched'], 'errors' => ['tree_observation_id', 'ground_truth_id']],
            ['payload' => ['decision' => 'false_positive', 'tree_observation_id' => $graph['tree'], 'ground_truth_id' => $graph['truth'], 'accepted_height_m' => 1], 'errors' => ['ground_truth_id', 'accepted_height_m']],
            ['payload' => ['decision' => 'false_negative', 'ground_truth_id' => $graph['truth'], 'tree_observation_id' => $graph['tree'], 'corrected_geometry' => ['type' => 'LineString', 'coordinates' => [181, -91, 0]]], 'errors' => ['tree_observation_id', 'corrected_geometry']],
            ['payload' => ['decision' => 'corrected', 'tree_observation_id' => $graph['tree'], 'ground_truth_id' => $graph['truth']], 'errors' => ['decision']],
            ['payload' => ['decision' => 'matched', 'tree_observation_id' => 'bad', 'ground_truth_id' => 'bad', 'accepted_height_m' => -1, 'accepted_age_years' => '1.234', 'validation_evidence' => [1, 2]], 'errors' => ['tree_observation_id', 'ground_truth_id', 'accepted_height_m', 'accepted_age_years', 'validation_evidence']],
        ];

        foreach ($cases as $case) {
            $this->withToken($graph['token'])->postJson($this->uri($graph['session']), $case['payload'])
                ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonValidationErrors($case['errors'], 'error.details');
        }
        $this->assertDatabaseCount('validation_matches', 0);
    }

    // [MATCH-01] All referenced evidence must belong to the scoped session and mission and remain active.
    public function test_it_hides_foreign_or_unavailable_references_and_rejects_duplicates(): void
    {
        $graph = $this->graph(prefix: 'scope-');
        foreach ([
            ['tree_observation_id' => $graph['foreign_tree'], 'ground_truth_id' => $graph['truth'], 'decision' => 'matched'],
            ['tree_observation_id' => $graph['tree'], 'ground_truth_id' => $graph['foreign_truth'], 'decision' => 'matched'],
            ['tree_observation_id' => $graph['tree'], 'ground_truth_id' => (string) Str::uuid(), 'decision' => 'matched'],
            ['tree_observation_id' => $graph['tree'], 'ground_truth_id' => $graph['truth'], 'decision' => 'corrected', 'accepted_species_id' => $graph['inactive_species']],
        ] as $payload) {
            $this->withToken($graph['token'])->postJson($this->uri($graph['session']), $payload)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }

        $payload = ['tree_observation_id' => $graph['tree'], 'ground_truth_id' => $graph['truth'], 'decision' => 'matched'];
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), $payload)->assertCreated();
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), $payload)
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        $this->assertDatabaseCount('validation_matches', 1);
    }

    // [MATCH-01] Closed and hidden sessions cannot receive decisions.
    public function test_it_enforces_session_state_and_tenant_scope(): void
    {
        $graph = $this->graph(prefix: 'session-');
        DB::table('validation_sessions')->where('validation_session_id', $graph['session'])->update(['status' => 'completed']);
        $payload = ['tree_observation_id' => $graph['tree'], 'ground_truth_id' => $graph['truth'], 'decision' => 'matched'];
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), $payload)
            ->assertConflict()->assertJsonPath('error.details.status', 'completed');

        foreach ([$graph['foreign_session'], (string) Str::uuid(), 'bad'] as $session) {
            $this->withToken($graph['token'])->postJson($this->uri($session), $payload)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $this->assertDatabaseCount('validation_matches', 0);
    }

    // [MATCH-01] Authentication and a tenant-local permission protect the mutation.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $graph = $this->graph(permissions: [], prefix: 'access-');
        $payload = ['tree_observation_id' => $graph['tree'], 'ground_truth_id' => $graph['truth'], 'decision' => 'matched'];
        $this->postJson($this->uri($graph['session']), $payload)->assertUnauthorized();
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), $payload)->assertForbidden();
    }

    // [MATCH-01] An inactive identity is rejected before the decision service runs.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor'])->update(['status' => 'inactive']);
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), [
            'tree_observation_id' => $graph['tree'], 'ground_truth_id' => $graph['truth'], 'decision' => 'matched',
        ])->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [MATCH-01] Authenticated throttling prevents an additional decision and audit event.
    public function test_it_rate_limits_decision_creation(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->graph(prefix: 'limited-');
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), [
            'tree_observation_id' => $graph['tree'], 'ground_truth_id' => $graph['truth'], 'decision' => 'matched',
        ])->assertCreated();
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), [
            'ground_truth_id' => $graph['other_truth'], 'decision' => 'false_negative',
        ])->assertTooManyRequests();
        $this->assertDatabaseCount('validation_matches', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [MATCH-01] Decision, tree outcome, and audit evidence share one transaction.
    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $graph = $this->graph(prefix: 'rollback-');
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), [
            'tree_observation_id' => $graph['tree'], 'ground_truth_id' => $graph['truth'], 'decision' => 'matched',
        ])->assertInternalServerError();
        $this->assertDatabaseCount('validation_matches', 0);
        $this->assertDatabaseHas('tree_observations', [
            'tree_observation_id' => $graph['tree'], 'validation_status' => 'unvalidated',
        ]);
    }

    // [MATCH-01] PostgreSQL enforces asymmetric decision references and Point(4326) correction evidence.
    public function test_postgresql_enforces_decision_shape_and_geometry(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL constraint assertion.');
        }

        $graph = $this->graph(prefix: 'pgsql-');
        $this->expectException(QueryException::class);
        DB::table('validation_matches')->insert([
            'validation_match_id' => (string) Str::uuid(),
            'validation_session_id' => $graph['session'],
            'ground_truth_id' => null,
            'tree_observation_id' => null,
            'match_status' => 'false_positive',
            'validated_by' => $graph['actor'],
            'validated_at' => now(),
        ]);
    }

    // [MATCH-01] Route, approved additive schema, and least-privilege writes are versioned.
    public function test_it_registers_the_route_schema_and_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/validation-sessions/{session}/decisions'
            && in_array('POST', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:validation.decide', $route->gatherMiddleware());

        $migration = file_get_contents(database_path('migrations/2026_08_25_000200_add_validation_decision_fields.php'));
        $dcl = file_get_contents(database_path('sql/dcl/051_validation_decision_grants.sql'));
        $this->assertIsString($migration);
        foreach (['validation_session_id', 'accepted_species_id', 'corrected_geometry', 'validation_evidence', 'validation_matches_reference_shape_check'] as $fragment) {
            $this->assertStringContainsString($fragment, $migration);
        }
        $this->assertIsString($dcl);
        $this->assertStringContainsString('ON TABLE app.validation_matches TO mangroscan_api_rw;', $dcl);
        $this->assertStringContainsString('ON TABLE app.tree_observations TO mangroscan_api_rw;', $dcl);
        foreach (['GRANT DELETE', 'mangroscan_worker', 'mangroscan_auditor'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    private function uri(string $session): string
    {
        return '/api/v1/validation-sessions/'.$session.'/decisions';
    }

    /** @param list<string> $permissions @return array<string, string> */
    private function graph(array $permissions = ['validation.decide'], string $prefix = ''): array
    {
        $ids = [];
        foreach ([
            'organization', 'foreign_organization', 'actor', 'foreign_actor', 'site', 'foreign_site',
            'mission', 'foreign_mission', 'drone', 'foreign_drone', 'flight', 'foreign_flight',
            'tree', 'foreign_tree', 'session', 'foreign_session', 'truth', 'other_truth',
            'foreign_truth', 'species', 'other_species', 'inactive_species',
        ] as $key) {
            $ids[$key] = (string) Str::uuid();
        }
        $now = now();
        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization'], 'organization_name' => $prefix.'Decision Org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['organization_id' => $ids['foreign_organization'], 'organization_name' => $prefix.'Foreign Decision Org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->user($ids['actor'], $ids['organization'], $prefix.'decision@example.test');
        $this->user($ids['foreign_actor'], $ids['foreign_organization'], $prefix.'foreign-decision@example.test');
        $this->site($ids['site'], $ids['organization'], $ids['actor'], $prefix.'DEC-SITE');
        $this->site($ids['foreign_site'], $ids['foreign_organization'], $ids['foreign_actor'], $prefix.'FOREIGN-DEC-SITE');
        $this->mission($ids['mission'], $ids['site'], $ids['actor'], $prefix.'DEC-MISSION');
        $this->mission($ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor'], $prefix.'FOREIGN-DEC-MISSION');
        $this->droneFlight($ids['drone'], $ids['flight'], $ids['organization'], $ids['mission'], $ids['actor'], $prefix.'DEC');
        $this->droneFlight($ids['foreign_drone'], $ids['foreign_flight'], $ids['foreign_organization'], $ids['foreign_mission'], $ids['foreign_actor'], $prefix.'FOREIGN-DEC');
        $this->validationSession($ids['session'], $ids['mission'], $ids['site'], $ids['actor']);
        $this->validationSession($ids['foreign_session'], $ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor']);
        DB::table('mangrove_species')->insert([
            ['species_id' => $ids['species'], 'scientific_name' => $prefix.'Rhizophora apiculata', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['species_id' => $ids['other_species'], 'scientific_name' => $prefix.'Avicennia marina', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['species_id' => $ids['inactive_species'], 'scientific_name' => $prefix.'Inactive species', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->tree($ids['tree'], $ids['mission'], $ids['flight'], $ids['species'], $prefix.'TREE', [123.8, 10.1]);
        $this->tree($ids['foreign_tree'], $ids['foreign_mission'], $ids['foreign_flight'], $ids['species'], $prefix.'FOREIGN-TREE', [124.8, 11.1]);
        $this->truth($ids['truth'], $ids['session'], $ids['species'], [123.8001, 10.1001]);
        $this->truth($ids['other_truth'], $ids['session'], $ids['other_species'], [123.802, 10.102]);
        $this->truth($ids['foreign_truth'], $ids['foreign_session'], $ids['species'], [124.8001, 11.1001]);

        $role = (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $ids['organization'], 'role_name' => $prefix.'Decision role', 'role_code' => $prefix.'decision_role', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('user_roles')->insert(['user_id' => $ids['actor'], 'role_id' => $role, 'created_at' => $now, 'updated_at' => $now]);
        foreach ($permissions as $permission) {
            $permissionId = DB::table('permissions')->where('permission_code', $permission)->value('permission_id') ?? (string) Str::uuid();
            DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => $permission, 'permission_name' => $permission, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
        }
        /** @var User $actor */
        $actor = User::query()->findOrFail($ids['actor']);

        return $ids + ['token' => $actor->createToken($prefix.'validation-decision')->plainTextToken];
    }

    private function user(string $id, string $organization, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organization, 'first_name' => 'Decision', 'last_name' => 'Reviewer', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $organization, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organization, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $site, string $actor, string $code): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $site, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Validate tree decisions.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function droneFlight(string $drone, string $flight, string $organization, string $mission, string $actor, string $code): void
    {
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $organization, 'drone_name' => $code, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function validationSession(string $id, string $mission, string $site, string $actor): void
    {
        DB::table('validation_sessions')->insert(['validation_session_id' => $id, 'mission_id' => $mission, 'site_id' => $site, 'validated_by' => $actor, 'validation_date' => '2026-08-25', 'method' => 'ground_survey', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array{0:float,1:float} $coordinates */
    private function tree(string $id, string $mission, string $flight, string $species, string $code, array $coordinates): void
    {
        DB::table('tree_observations')->insert(['tree_observation_id' => $id, 'mission_id' => $mission, 'flight_session_id' => $flight, 'tree_code' => $code, 'tree_location' => $this->point($coordinates), 'detection_confidence' => 0.8, 'final_species_id' => $species, 'final_height_meters' => 10, 'final_estimated_age_years' => 8, 'validation_status' => 'unvalidated', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array{0:float,1:float} $coordinates */
    private function truth(string $id, string $session, string $species, array $coordinates): void
    {
        DB::table('ground_truth_tree_records')->insert(['ground_truth_id' => $id, 'validation_session_id' => $session, 'species_id' => $species, 'ground_location' => $this->point($coordinates), 'measured_height_meters' => 8, 'estimated_age_years' => 6, 'health_status' => 'healthy', 'is_tree' => true, 'created_at' => now()]);
    }

    /** @param array{0:float,1:float} $coordinates */
    private function point(array $coordinates): mixed
    {
        $json = json_encode(['type' => 'Point', 'coordinates' => $coordinates], JSON_THROW_ON_ERROR);

        return DB::getDriverName() === 'pgsql'
            ? DB::raw("ST_SetSRID(ST_GeomFromGeoJSON('$json'), 4326)")
            : $json;
    }

    /** @return array{type:string,coordinates:array{0:float,1:float}} */
    private function treeGeometry(string $id): array
    {
        $value = DB::getDriverName() === 'pgsql'
            ? DB::table('tree_observations')->where('tree_observation_id', $id)->selectRaw('ST_AsGeoJSON(tree_location)::json AS geometry')->value('geometry')
            : DB::table('tree_observations')->where('tree_observation_id', $id)->value('tree_location');

        return is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : (array) $value;
    }
}
