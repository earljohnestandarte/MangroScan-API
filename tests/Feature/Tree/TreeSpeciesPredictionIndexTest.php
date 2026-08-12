<?php

namespace Tests\Feature\Tree;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TreeSpeciesPredictionIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_exact_final_first_prediction_history(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])->getJson('/api/v1/tree-observations/'.$graph['tree_id'].'/species');
        $response->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.classification_result_id', $graph['final_id'])
            ->assertJsonPath('data.1.rank_no', 1)->assertJsonPath('data.2.rank_no', 2)
            ->assertJsonPath('data.0.classification_basis.leaf_shape', 'elliptic');
        $this->assertSame(['classification_result_id', 'tree_observation_id', 'model_run_id', 'predicted_species_id', 'confidence_score', 'rank_no', 'classification_basis', 'is_final', 'created_at'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString('model_file_path', $response->getContent());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_returns_an_empty_history(): void
    {
        $graph = $this->createGraph(empty: true);
        $this->withToken($graph['token'])->getJson('/api/v1/tree-observations/'.$graph['tree_id'].'/species')
            ->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_it_enforces_tenant_path_and_deleted_lineage_boundaries(): void
    {
        $graph = $this->createGraph();
        foreach ([$graph['foreign_tree_id'], (string) Str::uuid(), 'bad-id'] as $tree) {
            $this->withToken($graph['token'])->getJson('/api/v1/tree-observations/'.$tree.'/species')->assertNotFound();
        }
        DB::table('survey_sites')->where('site_id', $graph['site_id'])->update(['deleted_at' => now()]);
        $this->withToken($graph['token'])->getJson('/api/v1/tree-observations/'.$graph['tree_id'].'/species')->assertNotFound();
    }

    public function test_it_enforces_access_and_throttling(): void
    {
        $auth = $this->createGraph(prefix: 'auth-');
        $url = '/api/v1/tree-observations/'.$auth['tree_id'].'/species';
        $this->getJson($url)->assertUnauthorized();
        $missing = $this->createGraph(prefix: 'missing-', permission: false);
        $this->app['auth']->forgetGuards();
        $this->withToken($missing['token'])->getJson('/api/v1/tree-observations/'.$missing['tree_id'].'/species')->assertForbidden();
        $inactive = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor_id'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->withToken($inactive['token'])->getJson('/api/v1/tree-observations/'.$inactive['tree_id'].'/species')->assertForbidden();
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $limited = $this->createGraph(prefix: 'limited-');
        $this->app['auth']->forgetGuards();
        $url = '/api/v1/tree-observations/'.$limited['tree_id'].'/species';
        $this->withToken($limited['token'])->getJson($url)->assertOk();
        $this->withToken($limited['token'])->getJson($url)->assertTooManyRequests();
    }

    public function test_it_reuses_result_read_only_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/035_tree_observation_detail_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('app.species_classification_results', $dcl);
        $this->assertStringContainsString('TO mangroscan_api_rw;', $dcl);
        $this->assertStringNotContainsString('DELETE', $dcl);
    }

    private function createGraph(string $prefix = '', bool $permission = true, bool $empty = false): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignActor = (string) Str::uuid();
        DB::table('organizations')->insert([['organization_id' => $org, 'organization_name' => $prefix.'Species Result Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Species Result Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($actor, $org, $prefix.'species-result@example.test');
        $this->user($foreignActor, $foreignOrg, $prefix.'foreign-species-result@example.test');
        $role = (string) Str::uuid();
        $permissionId = DB::table('permissions')->where('permission_code', 'results.read')->value('permission_id') ?? (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Species Reader', 'role_code' => $prefix.'species_reader', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'results.read', 'permission_name' => 'Read results', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $foreign = $this->lineage($foreignOrg, $foreignActor, $prefix.'FOREIGN');
        $species = (string) Str::uuid();
        DB::table('mangrove_species')->insert(['species_id' => $species, 'scientific_name' => $prefix.'Rhizophora apiculata', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        [$run,$media] = $this->modelRun($local['mission'], $local['flight'], $actor, $prefix);
        $tree = $this->tree($local['mission'], $local['flight'], $run, $media, 'TREE-RESULT');
        $foreignTree = $this->tree($foreign['mission'], $foreign['flight'], null, null, 'TREE-FOREIGN');
        $final = (string) Str::uuid();
        if (! $empty) {
            DB::table('species_classification_results')->insert([['classification_result_id' => (string) Str::uuid(), 'tree_observation_id' => $tree, 'model_run_id' => $run, 'predicted_species_id' => $species, 'confidence_score' => 0.8, 'rank_no' => 2, 'classification_basis' => null, 'is_final' => false, 'created_at' => '2026-08-12T03:00:00Z'], ['classification_result_id' => (string) Str::uuid(), 'tree_observation_id' => $tree, 'model_run_id' => $run, 'predicted_species_id' => $species, 'confidence_score' => 0.9, 'rank_no' => 1, 'classification_basis' => null, 'is_final' => false, 'created_at' => '2026-08-12T03:01:00Z'], ['classification_result_id' => $final, 'tree_observation_id' => $tree, 'model_run_id' => $run, 'predicted_species_id' => $species, 'confidence_score' => 0.95, 'rank_no' => 3, 'classification_basis' => json_encode(['leaf_shape' => 'elliptic']), 'is_final' => true, 'created_at' => '2026-08-12T03:02:00Z']]);
        }

        return ['actor_id' => $actor, 'site_id' => $local['site'], 'tree_id' => $tree, 'foreign_tree_id' => $foreignTree, 'final_id' => $final, 'token' => User::query()->findOrFail($actor)->createToken($prefix.'species-results')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Species', 'last_name' => 'Reader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Species history.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => $code, 'model' => 'Test', 'serial_number' => $code.'-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);

        return ['site' => $site, 'mission' => $mission, 'flight' => $flight];
    }

    private function modelRun(string $mission, string $flight, string $actor, string $prefix): array
    {
        $media = (string) Str::uuid();
        DB::table('media_assets')->insert(['media_asset_id' => $media, 'flight_session_id' => $flight, 'uploaded_by_user_id' => $actor, 'file_name' => $prefix.'species.jpg', 'file_type' => 'image', 'mime_type' => 'image/jpeg', 'file_size_bytes' => 100, 'storage_key' => 'species/'.$media, 'quality_status' => 'acceptable', 'processing_status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        $service = (string) Str::uuid();
        $model = (string) Str::uuid();
        $version = (string) Str::uuid();
        $job = (string) Str::uuid();
        $run = (string) Str::uuid();
        DB::table('ai_services')->insert(['ai_service_id' => $service, 'service_name' => $prefix.'Species AI', 'base_url' => 'https://'.($prefix === '' ? '' : rtrim($prefix, '-').'.').'species.example.test', 'encrypted_api_key' => Crypt::encryptString('secret'), 'environment' => $prefix === '' ? 'production' : substr($prefix, 0, 49), 'enabled' => true, 'health_status' => 'healthy', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_models')->insert(['model_id' => $model, 'ai_service_id' => $service, 'external_model_key' => $prefix.'classifier', 'model_name' => $prefix.'Classifier', 'model_type' => 'species_classifier', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_model_versions')->insert(['model_version_id' => $version, 'model_id' => $model, 'version_label' => 'v1', 'model_file_path' => 'private/'.$version, 'is_deployed' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('processing_jobs')->insert(['processing_job_id' => $job, 'mission_id' => $mission, 'flight_session_id' => $flight, 'job_type' => 'classification', 'job_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('model_runs')->insert(['model_run_id' => $run, 'processing_job_id' => $job, 'model_version_id' => $version, 'run_type' => 'species_classification', 'input_media_id' => $media, 'run_status' => 'completed', 'created_at' => now()]);

        return [$run, $media];
    }

    private function tree(string $mission, string $flight, ?string $run, ?string $media, string $code): string
    {
        $id = (string) Str::uuid();
        $point = json_encode(['type' => 'Point', 'coordinates' => [123.3, 9.3]]);
        DB::table('tree_observations')->insert(['tree_observation_id' => $id, 'mission_id' => $mission, 'flight_session_id' => $flight, 'model_run_id' => $run, 'source_media_id' => $media, 'tree_code' => $code, 'tree_location' => DB::getDriverName() === 'pgsql' ? DB::raw("ST_SetSRID(ST_GeomFromGeoJSON('$point'),4326)") : $point, 'validation_status' => 'unvalidated', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
