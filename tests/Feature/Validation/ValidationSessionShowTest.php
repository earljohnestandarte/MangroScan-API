<?php

namespace Tests\Feature\Validation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ValidationSessionShowTest extends TestCase
{
    use RefreshDatabase;

    // [VAL-04] The exact six-key workspace contains safe, map-ready tenant evidence.
    public function test_it_returns_the_exact_validation_workspace(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_val_04')
            ->getJson('/api/v1/validation-sessions/'.$graph['session_id']);

        $response->assertOk()->assertHeader('X-Request-ID', 'req_val_04')
            ->assertJsonCount(1)
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('data.context.session.validation_session_id', $graph['session_id'])
            ->assertJsonPath('data.context.mission.mission_id', $graph['mission_id'])
            ->assertJsonPath('data.context.site.site_id', $graph['site_id'])
            ->assertJsonPath('data.context.plot.plot_id', $graph['plot_id'])
            ->assertJsonPath('data.context.validator.user_id', $graph['validator_id'])
            ->assertJsonPath('data.context.validator.display_name', 'Val Marie Validator')
            ->assertJsonCount(1, 'data.observations')
            ->assertJsonPath('data.observations.0.tree_observation_id', $graph['tree_id'])
            ->assertJsonPath('data.observations.0.tree_location.type', 'Point')
            ->assertJsonCount(1, 'data.ground_truth_records')
            ->assertJsonPath('data.ground_truth_records.0.ground_truth_id', $graph['ground_truth_id'])
            ->assertJsonPath('data.ground_truth_records.0.ground_location.type', 'Point')
            ->assertJsonCount(1, 'data.matches')
            ->assertJsonPath('data.matches.0.validation_match_id', $graph['match_id'])
            ->assertJsonCount(1, 'data.metrics')
            ->assertJsonPath('data.metrics.0.accuracy_metric_id', $graph['metric_id'])
            ->assertJsonCount(1, 'data.layers')
            ->assertJsonPath('data.layers.0.layer_id', $graph['layer_id']);

        $this->assertSame(
            ['context', 'observations', 'ground_truth_records', 'matches', 'metrics', 'layers'],
            array_keys($response->json('data')),
        );
        $this->assertSame(['session', 'mission', 'site', 'plot', 'validator'], array_keys($response->json('data.context')));
        $this->assertSame([
            'ground_truth_id', 'validation_session_id', 'field_code', 'species_id', 'ground_location',
            'measured_height_meters', 'estimated_age_years', 'diameter_cm',
            'crown_diameter_m', 'health_status', 'is_tree', 'remarks', 'created_at',
        ], array_keys($response->json('data.ground_truth_records.0')));
        $this->assertSame([
            'validation_match_id', 'validation_session_id', 'ground_truth_id', 'tree_observation_id', 'match_status',
            'accepted_species_id', 'accepted_height_m', 'accepted_age_years', 'corrected_geometry',
            'notes', 'validation_evidence',
            'distance_error_meters', 'species_correct', 'height_error_meters', 'age_error_years',
            'validated_by', 'validated_at',
        ], array_keys($response->json('data.matches.0')));
        $this->assertArrayNotHasKey('photo_path', $response->json('data.ground_truth_records.0'));
        $this->assertArrayNotHasKey('storage_key', $response->json('data.layers.0'));
        $this->assertStringNotContainsString('private/', $response->getContent());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [VAL-04] A valid workspace may have no plot or downstream evidence yet.
    public function test_it_returns_empty_workspace_collections_for_a_new_site_level_session(): void
    {
        $graph = $this->createGraph(prefix: 'empty-', evidence: false);

        $this->withToken($graph['token'])->getJson('/api/v1/validation-sessions/'.$graph['session_id'])
            ->assertOk()
            ->assertJsonPath('data.context.plot', null)
            ->assertJsonCount(0, 'data.observations')
            ->assertJsonCount(0, 'data.ground_truth_records')
            ->assertJsonCount(0, 'data.matches')
            ->assertJsonCount(0, 'data.metrics')
            ->assertJsonCount(0, 'data.layers');
    }

    // [VAL-04] Foreign, missing, malformed and corrupted-lineage sessions remain hidden.
    public function test_it_hides_unavailable_or_inconsistent_sessions(): void
    {
        $graph = $this->createGraph();

        foreach ([
            $graph['foreign_session_id'],
            $graph['inconsistent_site_session_id'],
            $graph['inconsistent_plot_session_id'],
            $graph['foreign_validator_session_id'],
            (string) Str::uuid(),
        ] as $id) {
            $this->withToken($graph['token'])->getJson('/api/v1/validation-sessions/'.$id)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $this->withToken($graph['token'])->getJson('/api/v1/validation-sessions/not-a-uuid')->assertNotFound();
    }

    // [VAL-04] Authentication and a current-tenant validation.read grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $this->getJson('/api/v1/validation-sessions/'.Str::uuid())->assertUnauthorized();

        $missing = $this->createGraph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->getJson('/api/v1/validation-sessions/'.$missing['session_id'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'validation.read');

        $foreign = $this->createGraph(permission: false, foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->getJson('/api/v1/validation-sessions/'.$foreign['session_id'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'validation.read');
    }

    // [VAL-04] Inactive identities cannot inspect a workspace.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($graph['token'])->getJson('/api/v1/validation-sessions/'.$graph['session_id'])
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [VAL-04] Workspace reads use the authenticated request budget without side effects.
    public function test_it_rate_limits_workspace_reads(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->getJson('/api/v1/validation-sessions/'.$graph['session_id'])->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/validation-sessions/'.$graph['session_id'])
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [VAL-04] The route and every reused SELECT grant are versioned.
    public function test_it_registers_the_route_and_reuses_read_only_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/validation-sessions/{session}'
            && in_array('GET', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:validation.read', $route->gatherMiddleware());
        $this->assertContains('throttle:auth.authenticated', $route->gatherMiddleware());

        $grants = [
            '034_tree_observation_grants.sql' => ['app.tree_observations'],
            '038_geospatial_layer_grants.sql' => ['app.geospatial_layers'],
            '046_jason_workflow_grants.sql' => [
                'app.validation_sessions', 'app.ground_truth_tree_records',
                'app.validation_matches', 'app.accuracy_metrics',
            ],
        ];
        foreach ($grants as $file => $tables) {
            $sql = file_get_contents(database_path('sql/dcl/'.$file));
            $this->assertIsString($sql);
            foreach ($tables as $table) {
                $this->assertStringContainsString($table, $sql);
            }
        }
    }

    /** @return array<string, string> */
    private function createGraph(
        bool $permission = true,
        bool $foreignPermission = false,
        string $prefix = '',
        bool $evidence = true,
    ): array {
        $ids = [];
        foreach ([
            'organization_id', 'foreign_organization_id', 'actor_id', 'validator_id', 'foreign_user_id',
            'site_id', 'foreign_site_id', 'mission_id', 'foreign_mission_id', 'plot_id', 'foreign_plot_id',
            'session_id', 'foreign_session_id', 'inconsistent_site_session_id',
            'inconsistent_plot_session_id', 'foreign_validator_session_id',
            'tree_id', 'ground_truth_id', 'match_id', 'metric_id', 'layer_id',
        ] as $key) {
            $ids[$key] = (string) Str::uuid();
        }

        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization_id'], 'organization_name' => $prefix.'Workspace Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $ids['foreign_organization_id'], 'organization_name' => $prefix.'Foreign Workspace Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($ids['actor_id'], $ids['organization_id'], 'Workspace', null, 'Reader', $prefix.'workspace-reader@example.test');
        $this->user($ids['validator_id'], $ids['organization_id'], 'Val', 'Marie', 'Validator', $prefix.'workspace-validator@example.test');
        $this->user($ids['foreign_user_id'], $ids['foreign_organization_id'], 'Foreign', null, 'Validator', $prefix.'foreign-workspace@example.test');

        $localRole = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRole, 'organization_id' => $ids['organization_id'], 'role_name' => $prefix.'Workspace Reader', 'role_code' => $prefix.'workspace_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $ids['foreign_organization_id'], 'role_name' => $prefix.'Foreign Workspace Reader', 'role_code' => $prefix.'foreign_workspace_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'validation.read')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'validation.read', 'permission_name' => 'Read validation data', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $localRole, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $ids['actor_id'], 'role_id' => $localRole, 'created_at' => now(), 'updated_at' => now()]);
        } elseif ($foreignPermission) {
            DB::table('role_permissions')->insert(['role_id' => $foreignRole, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $ids['actor_id'], 'role_id' => $foreignRole, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->site($ids['site_id'], $ids['organization_id'], $ids['actor_id'], $prefix.'WORKSPACE');
        $this->site($ids['foreign_site_id'], $ids['foreign_organization_id'], $ids['foreign_user_id'], $prefix.'FOREIGN-WORKSPACE');
        $this->mission($ids['mission_id'], $ids['site_id'], $ids['actor_id'], $prefix.'WORKSPACE');
        $this->mission($ids['foreign_mission_id'], $ids['foreign_site_id'], $ids['foreign_user_id'], $prefix.'FOREIGN-WORKSPACE');
        $this->plot($ids['plot_id'], $ids['site_id'], $prefix.'WORKSPACE-PLOT');
        $this->plot($ids['foreign_plot_id'], $ids['foreign_site_id'], $prefix.'FOREIGN-WORKSPACE-PLOT');

        $this->validationSession($ids['session_id'], $ids['mission_id'], $ids['site_id'], $evidence ? $ids['plot_id'] : null, $ids['validator_id']);
        $this->validationSession($ids['foreign_session_id'], $ids['foreign_mission_id'], $ids['foreign_site_id'], $ids['foreign_plot_id'], $ids['foreign_user_id']);
        $this->validationSession($ids['inconsistent_site_session_id'], $ids['mission_id'], $ids['foreign_site_id'], null, $ids['validator_id']);
        $this->validationSession($ids['inconsistent_plot_session_id'], $ids['mission_id'], $ids['site_id'], $ids['foreign_plot_id'], $ids['validator_id']);
        $this->validationSession($ids['foreign_validator_session_id'], $ids['mission_id'], $ids['site_id'], null, $ids['foreign_user_id']);

        if ($evidence) {
            $this->evidence($ids, $prefix);
        }

        return $ids + [
            'token' => User::query()->findOrFail($ids['actor_id'])->createToken($prefix.'validation-workspace')->plainTextToken,
        ];
    }

    /** @param array<string, string> $ids */
    private function evidence(array $ids, string $prefix): void
    {
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        $species = (string) Str::uuid();
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $ids['organization_id'], 'drone_name' => $prefix.'Workspace Drone', 'model' => 'Test', 'serial_number' => $prefix.'WORKSPACE-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $ids['mission_id'], 'drone_id' => $drone, 'pilot_user_id' => $ids['actor_id'], 'flight_code' => $prefix.'WORKSPACE-FLIGHT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mangrove_species')->insert(['species_id' => $species, 'scientific_name' => $prefix.'Rhizophora mucronata', 'common_name' => 'Bakauan', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $point = json_encode(['type' => 'Point', 'coordinates' => [123.81, 10.11]], JSON_THROW_ON_ERROR);
        $treeLocation = DB::getDriverName() === 'pgsql' ? DB::raw("ST_SetSRID(ST_GeomFromGeoJSON('$point'),4326)") : $point;
        DB::table('tree_observations')->insert([
            'tree_observation_id' => $ids['tree_id'], 'mission_id' => $ids['mission_id'],
            'flight_session_id' => $flight, 'tree_code' => $prefix.'TREE-001', 'tree_location' => $treeLocation,
            'detection_confidence' => 0.92, 'final_species_id' => $species,
            'final_height_meters' => 4.5, 'final_estimated_age_years' => 6,
            'validation_status' => 'validated', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ground_truth_tree_records')->insert([
            'ground_truth_id' => $ids['ground_truth_id'], 'validation_session_id' => $ids['session_id'],
            'species_id' => $species, 'ground_location' => $treeLocation,
            'measured_height_meters' => 4.4, 'estimated_age_years' => 6,
            'diameter_cm' => 22, 'health_status' => 'healthy',
            'photo_path' => 'private/validation/photo.jpg', 'remarks' => 'Verified.', 'created_at' => now(),
        ]);
        DB::table('validation_matches')->insert([
            'validation_match_id' => $ids['match_id'], 'validation_session_id' => $ids['session_id'],
            'ground_truth_id' => $ids['ground_truth_id'],
            'tree_observation_id' => $ids['tree_id'], 'match_status' => 'matched',
            'distance_error_meters' => 0.25, 'species_correct' => true,
            'height_error_meters' => 0.1, 'age_error_years' => 0,
            'validated_by' => $ids['validator_id'], 'validated_at' => now(),
        ]);
        DB::table('accuracy_metrics')->insert([
            'accuracy_metric_id' => $ids['metric_id'], 'validation_session_id' => $ids['session_id'],
            'mission_id' => $ids['mission_id'], 'metric_type' => 'species_accuracy',
            'metric_value' => 1, 'sample_size' => 1, 'computed_at' => now(), 'notes' => 'Workspace metric.',
        ]);
        DB::table('geospatial_layers')->insert([
            'layer_id' => $ids['layer_id'], 'mission_id' => $ids['mission_id'],
            'layer_name' => 'Tree Points', 'layer_type' => 'tree_points',
            'storage_key' => 'private/layers/'.$prefix.'tree-points.geojson',
            'style_config' => json_encode(['color' => '#228B22'], JSON_THROW_ON_ERROR),
            'is_visible_default' => true, 'created_by' => $ids['actor_id'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(string $id, string $organization, string $first, ?string $middle, string $last, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organization, 'first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'position_title' => 'Environmental Specialist', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $organization, string $creator, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organization, 'site_name' => $code.' Site', 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $creator, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $site, string $creator, string $code): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code.' Mission', 'mission_objective' => 'Review validation workspace.', 'mission_status' => 'completed', 'created_by' => $creator, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function plot(string $id, string $site, string $code): void
    {
        $geometry = json_encode(['type' => 'Polygon', 'coordinates' => [[[123.8, 10.1], [123.9, 10.1], [123.9, 10.2], [123.8, 10.1]]]], JSON_THROW_ON_ERROR);
        $values = [$id, $site, $code, $code, $geometry, now(), now()];
        if (DB::getDriverName() === 'pgsql') {
            DB::insert('INSERT INTO monitoring_plots (plot_id, site_id, plot_code, plot_name, plot_geom, created_at, updated_at) VALUES (?, ?, ?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?, ?)', $values);

            return;
        }
        DB::table('monitoring_plots')->insert(array_combine(['plot_id', 'site_id', 'plot_code', 'plot_name', 'plot_geom', 'created_at', 'updated_at'], $values));
    }

    private function validationSession(string $id, string $mission, string $site, ?string $plot, string $validator): void
    {
        DB::table('validation_sessions')->insert(['validation_session_id' => $id, 'mission_id' => $mission, 'site_id' => $site, 'plot_id' => $plot, 'validated_by' => $validator, 'validation_date' => '2026-08-24', 'method' => 'ground_survey', 'status' => 'open', 'notes' => 'Workspace.', 'created_at' => now(), 'updated_at' => now()]);
    }
}
