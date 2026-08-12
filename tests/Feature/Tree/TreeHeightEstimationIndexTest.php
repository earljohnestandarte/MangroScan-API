<?php

namespace Tests\Feature\Tree;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TreeHeightEstimationIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_exact_final_first_height_history(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])->getJson('/api/v1/tree-observations/'.$graph['tree_id'].'/heights');
        $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.height_estimation_id', $graph['final_id'])
            ->assertJsonPath('data.0.method', 'stereo_depth')->assertJsonPath('data.0.height_meters', '4.80');
        $this->assertSame(['height_estimation_id', 'tree_observation_id', 'model_run_id', 'method', 'height_meters', 'height_confidence_score', 'source_dataset_id', 'measurement_notes', 'is_final', 'created_at'], array_keys($response->json('data.0')));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_returns_empty_and_enforces_tenant_path_boundaries(): void
    {
        $empty = $this->createGraph(prefix: 'empty-', empty: true);
        $this->withToken($empty['token'])->getJson('/api/v1/tree-observations/'.$empty['tree_id'].'/heights')->assertOk()->assertExactJson(['data' => []]);
        $graph = $this->createGraph(prefix: 'boundary-');
        $this->app['auth']->forgetGuards();
        foreach ([$graph['foreign_tree_id'], (string) Str::uuid(), 'bad-id'] as $tree) {
            $this->withToken($graph['token'])->getJson('/api/v1/tree-observations/'.$tree.'/heights')->assertNotFound();
        }
        DB::table('survey_sites')->where('site_id', $graph['site_id'])->update(['deleted_at' => now()]);
        $this->withToken($graph['token'])->getJson('/api/v1/tree-observations/'.$graph['tree_id'].'/heights')->assertNotFound();
    }

    public function test_it_enforces_access_and_throttling(): void
    {
        $auth = $this->createGraph(prefix: 'auth-');
        $this->getJson('/api/v1/tree-observations/'.$auth['tree_id'].'/heights')->assertUnauthorized();
        $missing = $this->createGraph(prefix: 'missing-', permission: false);
        $this->app['auth']->forgetGuards();
        $this->withToken($missing['token'])->getJson('/api/v1/tree-observations/'.$missing['tree_id'].'/heights')->assertForbidden();
        $inactive = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor_id'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->withToken($inactive['token'])->getJson('/api/v1/tree-observations/'.$inactive['tree_id'].'/heights')->assertForbidden();
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $limited = $this->createGraph(prefix: 'limited-');
        $this->app['auth']->forgetGuards();
        $url = '/api/v1/tree-observations/'.$limited['tree_id'].'/heights';
        $this->withToken($limited['token'])->getJson($url)->assertOk();
        $this->withToken($limited['token'])->getJson($url)->assertTooManyRequests();
    }

    public function test_it_reuses_result_read_only_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/035_tree_observation_detail_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('app.canopy_height_estimations', $dcl);
        $this->assertStringContainsString('TO mangroscan_api_rw;', $dcl);
        $this->assertStringNotContainsString('DELETE', $dcl);
    }

    private function createGraph(string $prefix = '', bool $permission = true, bool $empty = false): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignActor = (string) Str::uuid();
        DB::table('organizations')->insert([['organization_id' => $org, 'organization_name' => $prefix.'Height Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Height Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($actor, $org, $prefix.'height@example.test');
        $this->user($foreignActor, $foreignOrg, $prefix.'foreign-height@example.test');
        $role = (string) Str::uuid();
        $permissionId = DB::table('permissions')->where('permission_code', 'results.read')->value('permission_id') ?? (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Height Reader', 'role_code' => $prefix.'height_reader', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'results.read', 'permission_name' => 'Read results', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $foreign = $this->lineage($foreignOrg, $foreignActor, $prefix.'FOREIGN');
        $tree = $this->tree($local['mission'], $local['flight'], 'TREE-HEIGHT');
        $foreignTree = $this->tree($foreign['mission'], $foreign['flight'], 'TREE-FOREIGN');
        $final = (string) Str::uuid();
        if (! $empty) {
            DB::table('canopy_height_estimations')->insert([['height_estimation_id' => (string) Str::uuid(), 'tree_observation_id' => $tree, 'method' => 'photogrammetry', 'height_meters' => 4.2, 'height_confidence_score' => 0.8, 'measurement_notes' => 'Initial estimate', 'is_final' => false, 'created_at' => '2026-08-12T03:00:00Z'], ['height_estimation_id' => $final, 'tree_observation_id' => $tree, 'method' => 'stereo_depth', 'height_meters' => 4.8, 'height_confidence_score' => 0.92, 'measurement_notes' => 'Final estimate', 'is_final' => true, 'created_at' => '2026-08-12T03:01:00Z']]);
        }

        return ['actor_id' => $actor, 'site_id' => $local['site'], 'tree_id' => $tree, 'foreign_tree_id' => $foreignTree, 'final_id' => $final, 'token' => User::query()->findOrFail($actor)->createToken($prefix.'heights')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Height', 'last_name' => 'Reader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Height history.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => $code, 'model' => 'Test', 'serial_number' => $code.'-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);

        return ['site' => $site, 'mission' => $mission, 'flight' => $flight];
    }

    private function tree(string $mission, string $flight, string $code): string
    {
        $id = (string) Str::uuid();
        $point = json_encode(['type' => 'Point', 'coordinates' => [123.3, 9.3]]);
        DB::table('tree_observations')->insert(['tree_observation_id' => $id, 'mission_id' => $mission, 'flight_session_id' => $flight, 'tree_code' => $code, 'tree_location' => DB::getDriverName() === 'pgsql' ? DB::raw("ST_SetSRID(ST_GeomFromGeoJSON('$point'),4326)") : $point, 'validation_status' => 'unvalidated', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
