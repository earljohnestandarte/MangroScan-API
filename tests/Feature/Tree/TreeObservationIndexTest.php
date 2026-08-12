<?php

namespace Tests\Feature\Tree;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TreeObservationIndexTest extends TestCase
{
    use RefreshDatabase;

    // [TREE-01] Canonical observations use the exact paginated PostGIS-safe resource.
    public function test_it_lists_tenant_observations_with_exact_geometry_and_pagination(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])->withHeader('X-Request-ID', 'req_tree_01')
            ->getJson('/api/v1/tree-observations?per_page=2&page=1');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_tree_01')
            ->assertJsonPath('meta', ['request_id' => 'req_tree_01', 'page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.tree_observation_id', $graph['latest_tree_id'])
            ->assertJsonPath('data.0.tree_code', 'TREE-003')
            ->assertJsonPath('data.0.tree_location.type', 'Point')
            ->assertJsonPath('data.0.tree_location.coordinates.0', 123.3055)
            ->assertJsonPath('data.0.crown_polygon.type', 'Polygon')
            ->assertJsonPath('data.0.bounding_box.width', 80)
            ->assertJsonPath('data.0.final_species_id', $graph['species_id'])
            ->assertJsonPath('data.0.validation_status', 'validated');
        $this->assertSame([
            'tree_observation_id', 'tree_entity_id', 'mission_id', 'flight_session_id',
            'model_run_id', 'source_media_id', 'tree_code', 'tree_location',
            'crown_polygon', 'bounding_box', 'detection_confidence', 'final_species_id',
            'final_height_meters', 'final_estimated_age_years', 'validation_status',
            'created_at', 'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [TREE-01] All documented filters compose after normalization.
    public function test_it_composes_every_documented_filter(): void
    {
        $graph = $this->createGraph();
        $query = http_build_query([
            'mission_id' => $graph['mission_id'],
            'flight_id' => $graph['flight_id'],
            'species_id' => $graph['species_id'],
            'validation_status' => ' VALIDATED ',
            'min_confidence' => '0.9000',
        ]);

        $this->withToken($graph['token'])->getJson('/api/v1/tree-observations?'.$query)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tree_observation_id', $graph['latest_tree_id']);
    }

    // [TREE-01] Invalid filters fail with the standard validation envelope.
    public function test_it_validates_observation_filters(): void
    {
        $graph = $this->createGraph();
        $this->withToken($graph['token'])->getJson('/api/v1/tree-observations?mission_id=nope&flight_id=nope&species_id=nope&validation_status=approved&min_confidence=1.1&page=0&per_page=101')
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors([
                'mission_id', 'flight_id', 'species_id', 'validation_status',
                'min_confidence', 'page', 'per_page',
            ], 'error.details');
    }

    // [TREE-01] Scoped filters hide foreign/missing resources and deleted data.
    public function test_it_enforces_tenant_filter_boundaries_and_soft_deletion(): void
    {
        $graph = $this->createGraph();
        foreach ([
            'mission_id='.$graph['foreign_mission_id'],
            'flight_id='.$graph['foreign_flight_id'],
            'mission_id='.(string) Str::uuid(),
            'species_id='.(string) Str::uuid(),
        ] as $query) {
            $this->withToken($graph['token'])->getJson('/api/v1/tree-observations?'.$query)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }

        DB::table('survey_sites')->where('site_id', $graph['site_id'])->update(['deleted_at' => now()]);
        $this->withToken($graph['token'])->getJson('/api/v1/tree-observations')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    // [TREE-01] Authentication, tenant-valid results permission and active identity are mandatory.
    public function test_it_enforces_access_control(): void
    {
        $auth = $this->createGraph(prefix: 'auth-');
        $this->getJson('/api/v1/tree-observations')->assertUnauthorized();

        $missing = $this->createGraph(prefix: 'missing-', permission: false);
        $this->app['auth']->forgetGuards();
        $this->withToken($missing['token'])->getJson('/api/v1/tree-observations')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'results.read');

        $foreignRole = $this->createGraph(prefix: 'foreign-role-', foreignPermission: true);
        $this->app['auth']->forgetGuards();
        $this->withToken($foreignRole['token'])->getJson('/api/v1/tree-observations')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'results.read');

        $inactive = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor_id'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->withToken($inactive['token'])->getJson('/api/v1/tree-observations')
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [TREE-01] Observation reads share the authenticated request budget.
    public function test_it_rate_limits_observation_lists(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $this->withToken($graph['token'])->getJson('/api/v1/tree-observations')->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/tree-observations')
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [TREE-01] Canonical domains, PostGIS types and split read/worker DCL are version controlled.
    public function test_it_versions_tree_schema_constraints_and_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_065300_create_tree_observation_tables.php'));
        $dcl = file_get_contents(database_path('sql/dcl/034_tree_observation_grants.sql'));
        $this->assertIsString($migration);
        foreach ([
            "geometry('tree_location', 'point', 4326)",
            "geometry('crown_polygon', 'polygon', 4326)",
            "->unique(['mission_id', 'tree_code'])",
            'tree_observations_confidence_check',
            'tree_observations_validation_status_check',
        ] as $guard) {
            $this->assertStringContainsString($guard, $migration);
        }
        $this->assertIsString($dcl);
        $this->assertStringContainsString('app.tree_observations TO mangroscan_api_rw, mangroscan_report_ro;', $dcl);
        $this->assertStringContainsString('ON TABLE app.tree_observations TO mangroscan_worker;', $dcl);
        $this->assertStringNotContainsString('TO mangroscan_api_rw;\n\nGRANT INSERT', $dcl);
        $this->assertStringNotContainsString('DELETE', $dcl);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $graph = $this->createGraph(prefix: 'constraint-');
        $this->expectException(QueryException::class);
        DB::table('tree_observations')->where('tree_observation_id', $graph['latest_tree_id'])
            ->update(['detection_confidence' => 1.1]);
    }

    /** @return array<string, string> */
    private function createGraph(
        string $prefix = '',
        bool $permission = true,
        bool $foreignPermission = false,
    ): array {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignUser = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => $prefix.'Tree Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Tree Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, $prefix.'trees@example.test');
        $this->user($foreignUser, $foreignOrg, $prefix.'foreign-trees@example.test');
        $localRole = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRole, 'organization_id' => $org, 'role_name' => $prefix.'Results Reader', 'role_code' => $prefix.'results_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $foreignOrg, 'role_name' => $prefix.'Foreign Results Reader', 'role_code' => $prefix.'foreign_results_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'results.read')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'results.read', 'permission_name' => 'Read results', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission || $foreignPermission) {
            $role = $foreignPermission ? $foreignRole : $localRole;
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }

        $local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $foreign = $this->lineage($foreignOrg, $foreignUser, $prefix.'FOREIGN');
        $species = $this->species($prefix.'Rhizophora mucronata', 'Bakauan');
        $otherSpecies = $this->species($prefix.'Avicennia marina', 'Bungalon');
        $latest = (string) Str::uuid();
        $this->observation((string) Str::uuid(), $local['mission'], $local['flight'], 'TREE-001', null, 'unvalidated', 0.55, '2026-08-12T01:00:00+00:00', 123.3051, 9.3061);
        $this->observation((string) Str::uuid(), $local['mission'], $local['flight'], 'TREE-002', $otherSpecies, 'corrected', 0.88, '2026-08-12T02:00:00+00:00', 123.3053, 9.3063);
        $this->observation($latest, $local['mission'], $local['flight'], 'TREE-003', $species, 'validated', 0.96, '2026-08-12T03:00:00+00:00', 123.3055, 9.3065, crown: true);
        $this->observation((string) Str::uuid(), $local['mission'], $local['flight'], 'TREE-DELETED', $species, 'validated', 0.99, '2026-08-12T04:00:00+00:00', 123.3057, 9.3067, deleted: true);
        $this->observation((string) Str::uuid(), $foreign['mission'], $foreign['flight'], 'TREE-FOREIGN', $species, 'validated', 0.99, '2026-08-12T05:00:00+00:00', 123.4, 9.4);

        return [
            'actor_id' => $actor, 'site_id' => $local['site'],
            'mission_id' => $local['mission'], 'flight_id' => $local['flight'],
            'foreign_mission_id' => $foreign['mission'], 'foreign_flight_id' => $foreign['flight'],
            'species_id' => $species, 'latest_tree_id' => $latest,
            'token' => User::query()->findOrFail($actor)->createToken($prefix.'trees')->plainTextToken,
        ];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Tree', 'last_name' => 'Reader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{site:string,mission:string,flight:string} */
    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Inspect trees.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => $code, 'model' => 'Test', 'serial_number' => $code.'-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);

        return ['site' => $site, 'mission' => $mission, 'flight' => $flight];
    }

    private function species(string $scientificName, string $commonName): string
    {
        $id = (string) Str::uuid();
        DB::table('mangrove_species')->insert(['species_id' => $id, 'scientific_name' => $scientificName, 'common_name' => $commonName, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function observation(
        string $id,
        string $mission,
        string $flight,
        string $code,
        ?string $species,
        string $status,
        float $confidence,
        string $createdAt,
        float $longitude,
        float $latitude,
        bool $crown = false,
        bool $deleted = false,
    ): void {
        $point = json_encode(['type' => 'Point', 'coordinates' => [$longitude, $latitude]], JSON_THROW_ON_ERROR);
        $polygon = json_encode(['type' => 'Polygon', 'coordinates' => [[
            [$longitude, $latitude], [$longitude + 0.0001, $latitude],
            [$longitude + 0.0001, $latitude + 0.0001], [$longitude, $latitude + 0.0001],
            [$longitude, $latitude],
        ]]], JSON_THROW_ON_ERROR);
        DB::table('tree_observations')->insert([
            'tree_observation_id' => $id, 'mission_id' => $mission,
            'flight_session_id' => $flight, 'tree_code' => $code,
            'tree_location' => DB::getDriverName() === 'pgsql' ? DB::raw("ST_SetSRID(ST_GeomFromGeoJSON('$point'),4326)") : $point,
            'crown_polygon' => $crown
                ? (DB::getDriverName() === 'pgsql' ? DB::raw("ST_SetSRID(ST_GeomFromGeoJSON('$polygon'),4326)") : $polygon)
                : null,
            'bounding_box' => json_encode(['x' => 20, 'y' => 30, 'width' => 80, 'height' => 90], JSON_THROW_ON_ERROR),
            'detection_confidence' => $confidence, 'final_species_id' => $species,
            'final_height_meters' => 4.82, 'final_estimated_age_years' => 6.1,
            'validation_status' => $status, 'created_at' => $createdAt,
            'updated_at' => $createdAt, 'deleted_at' => $deleted ? $createdAt : null,
        ]);
    }
}
