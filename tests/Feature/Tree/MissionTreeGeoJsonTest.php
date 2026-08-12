<?php

namespace Tests\Feature\Tree;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MissionTreeGeoJsonTest extends TestCase
{
    use RefreshDatabase;

    // [TREE-03] Mission trees are returned as an exact, deterministic GeoJSON FeatureCollection.
    public function test_it_returns_map_ready_tree_features(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->getJson('/api/v1/missions/'.$graph['mission_id'].'/trees.geojson');

        $response->assertOk()->assertHeader('Content-Type', 'application/geo+json')
            ->assertJsonPath('type', 'FeatureCollection')->assertJsonCount(3, 'features')
            ->assertJsonPath('features.0.type', 'Feature')
            ->assertJsonPath('features.0.id', $graph['first_tree_id'])
            ->assertJsonPath('features.0.geometry.type', 'Point')
            ->assertJsonPath('features.0.geometry.coordinates.0', 123.301)
            ->assertJsonPath('features.0.properties.tree_code', 'TREE-001')
            ->assertJsonPath('features.0.properties.validation_status', 'validated');
        $this->assertSame(['type', 'features'], array_keys($response->json()));
        $this->assertSame(['type', 'id', 'geometry', 'properties'], array_keys($response->json('features.0')));
        $this->assertSame([
            'tree_observation_id', 'tree_entity_id', 'tree_code', 'flight_session_id',
            'detection_confidence', 'final_species_id', 'final_height_meters',
            'final_estimated_age_years', 'validation_status',
        ], array_keys($response->json('features.0.properties')));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [TREE-03] Species and validated-only filters compose without leaking deleted trees.
    public function test_it_filters_features_by_species_and_validation(): void
    {
        $graph = $this->createGraph();
        $query = http_build_query(['species_id' => $graph['species_id'], 'validated_only' => true]);
        $this->withToken($graph['token'])
            ->getJson('/api/v1/missions/'.$graph['mission_id'].'/trees.geojson?'.$query)
            ->assertOk()->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.id', $graph['first_tree_id']);
    }

    // [TREE-03] Empty missions retain a valid GeoJSON FeatureCollection.
    public function test_it_returns_an_empty_feature_collection(): void
    {
        $graph = $this->createGraph();
        DB::table('tree_observations')->where('mission_id', $graph['mission_id'])->update(['deleted_at' => now()]);
        $this->withToken($graph['token'])->getJson('/api/v1/missions/'.$graph['mission_id'].'/trees.geojson')
            ->assertOk()->assertExactJson(['type' => 'FeatureCollection', 'features' => []]);
    }

    // [TREE-03] Invalid filters and inaccessible mission/species identifiers are rejected safely.
    public function test_it_validates_filters_and_hides_inaccessible_resources(): void
    {
        $graph = $this->createGraph();
        $base = '/api/v1/missions/'.$graph['mission_id'].'/trees.geojson';
        $this->withToken($graph['token'])->getJson($base.'?species_id=nope&validated_only=maybe')
            ->assertUnprocessable()->assertJsonValidationErrors(['species_id', 'validated_only'], 'error.details');
        foreach ([$graph['foreign_mission_id'], (string) Str::uuid()] as $mission) {
            $this->withToken($graph['token'])->getJson('/api/v1/missions/'.$mission.'/trees.geojson')
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $this->withToken($graph['token'])->getJson($base.'?species_id='.(string) Str::uuid())
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // [TREE-03] Authentication, tenant-valid permission and active identity are mandatory.
    public function test_it_enforces_access_control(): void
    {
        $auth = $this->createGraph(prefix: 'auth-');
        $this->getJson('/api/v1/missions/'.$auth['mission_id'].'/trees.geojson')->assertUnauthorized();

        $missing = $this->createGraph(prefix: 'missing-', permission: false);
        $this->app['auth']->forgetGuards();
        $this->withToken($missing['token'])->getJson('/api/v1/missions/'.$missing['mission_id'].'/trees.geojson')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'results.read');

        $inactive = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor_id'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->withToken($inactive['token'])->getJson('/api/v1/missions/'.$inactive['mission_id'].'/trees.geojson')
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [TREE-03] GeoJSON reads share the authenticated request budget.
    public function test_it_rate_limits_geojson_reads(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $url = '/api/v1/missions/'.$graph['mission_id'].'/trees.geojson';
        $this->withToken($graph['token'])->getJson($url)->assertOk();
        $this->withToken($graph['token'])->getJson($url)
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    /** @return array<string, string> */
    private function createGraph(string $prefix = '', bool $permission = true): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignActor = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => $prefix.'GeoJSON Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign GeoJSON Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, $prefix.'geojson@example.test');
        $this->user($foreignActor, $foreignOrg, $prefix.'foreign-geojson@example.test');
        $role = (string) Str::uuid();
        $permissionId = DB::table('permissions')->where('permission_code', 'results.read')->value('permission_id') ?? (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Map Reader', 'role_code' => $prefix.'map_reader', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'results.read', 'permission_name' => 'Read results', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $foreign = $this->lineage($foreignOrg, $foreignActor, $prefix.'FOREIGN');
        $species = $this->species($prefix.'Rhizophora stylosa');
        $otherSpecies = $this->species($prefix.'Sonneratia alba');
        $first = (string) Str::uuid();
        $this->tree($first, $local['mission'], $local['flight'], 'TREE-001', $species, 'validated', 123.301);
        $this->tree((string) Str::uuid(), $local['mission'], $local['flight'], 'TREE-002', $species, 'unvalidated', 123.302);
        $this->tree((string) Str::uuid(), $local['mission'], $local['flight'], 'TREE-003', $otherSpecies, 'corrected', 123.303);
        $this->tree((string) Str::uuid(), $local['mission'], $local['flight'], 'TREE-004', $species, 'validated', 123.304, true);
        $this->tree((string) Str::uuid(), $foreign['mission'], $foreign['flight'], 'TREE-FOREIGN', $species, 'validated', 123.4);

        return ['actor_id' => $actor, 'mission_id' => $local['mission'], 'foreign_mission_id' => $foreign['mission'], 'species_id' => $species, 'first_tree_id' => $first, 'token' => User::query()->findOrFail($actor)->createToken($prefix.'geojson')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Map', 'last_name' => 'Reader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{mission:string,flight:string} */
    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Map trees.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => $code, 'model' => 'Test', 'serial_number' => $code.'-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);

        return ['mission' => $mission, 'flight' => $flight];
    }

    private function species(string $scientificName): string
    {
        $id = (string) Str::uuid();
        DB::table('mangrove_species')->insert(['species_id' => $id, 'scientific_name' => $scientificName, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function tree(string $id, string $mission, string $flight, string $code, string $species, string $status, float $longitude, bool $deleted = false): void
    {
        $point = json_encode(['type' => 'Point', 'coordinates' => [$longitude, 9.3065]], JSON_THROW_ON_ERROR);
        DB::table('tree_observations')->insert(['tree_observation_id' => $id, 'mission_id' => $mission, 'flight_session_id' => $flight, 'tree_code' => $code, 'tree_location' => DB::getDriverName() === 'pgsql' ? DB::raw("ST_SetSRID(ST_GeomFromGeoJSON('$point'),4326)") : $point, 'detection_confidence' => 0.94, 'final_species_id' => $species, 'final_height_meters' => 4.8, 'final_estimated_age_years' => 6.0, 'validation_status' => $status, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }
}
