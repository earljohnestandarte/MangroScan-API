<?php

namespace Tests\Feature\Tree;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TreeObservationShowTest extends TestCase
{
    use RefreshDatabase;

    // [TREE-02] Detail returns exact tree, result histories, source media and run provenance.
    public function test_it_shows_complete_tree_result_provenance(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])->withHeader('X-Request-ID', 'req_tree_02')
            ->getJson('/api/v1/tree-observations/'.$graph['tree_id']);

        $response->assertOk()->assertHeader('X-Request-ID', 'req_tree_02')
            ->assertJsonPath('meta.request_id', 'req_tree_02')
            ->assertJsonPath('data.tree.tree_observation_id', $graph['tree_id'])
            ->assertJsonPath('data.tree.tree_location.type', 'Point')
            ->assertJsonPath('data.species_predictions.0.classification_result_id', $graph['final_prediction_id'])
            ->assertJsonPath('data.species_predictions.0.classification_basis.canopy_texture', 'dense')
            ->assertJsonPath('data.height_estimations.0.height_estimation_id', $graph['final_height_id'])
            ->assertJsonPath('data.age_estimations.0.age_estimation_id', $graph['final_age_id'])
            ->assertJsonPath('data.age_estimations.0.assumptions', 'Stable estuarine growth conditions.')
            ->assertJsonPath('data.source_media.media_asset_id', $graph['media_id'])
            ->assertJsonPath('data.model_run.model_run_id', $graph['model_run_id']);
        $this->assertSame([
            'tree', 'species_predictions', 'height_estimations', 'age_estimations',
            'source_media', 'model_run',
        ], array_keys($response->json('data')));
        $this->assertStringNotContainsString('storage_key', $response->getContent());
        $this->assertStringNotContainsString('model_file_path', $response->getContent());
        $this->assertStringNotContainsString('encrypted_api_key', $response->getContent());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [TREE-02] Optional provenance and result collections preserve the exact envelope.
    public function test_it_returns_empty_results_and_null_optional_provenance(): void
    {
        $graph = $this->createGraph(empty: true);
        $this->withToken($graph['token'])->getJson('/api/v1/tree-observations/'.$graph['tree_id'])
            ->assertOk()
            ->assertJsonPath('data.species_predictions', [])
            ->assertJsonPath('data.height_estimations', [])
            ->assertJsonPath('data.age_estimations', [])
            ->assertJsonPath('data.source_media', null)
            ->assertJsonPath('data.model_run', null);
    }

    // [TREE-02] Foreign, missing, malformed and deleted-lineage trees remain hidden.
    public function test_it_enforces_tenant_and_path_boundaries(): void
    {
        $graph = $this->createGraph();
        foreach ([$graph['foreign_tree_id'], (string) Str::uuid(), 'not-a-uuid'] as $treeId) {
            $this->withToken($graph['token'])->getJson('/api/v1/tree-observations/'.$treeId)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }

        DB::table('survey_sites')->where('site_id', $graph['site_id'])->update(['deleted_at' => now()]);
        $this->withToken($graph['token'])->getJson('/api/v1/tree-observations/'.$graph['tree_id'])
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // [TREE-02] Authentication, tenant-valid permission and active identity are mandatory.
    public function test_it_enforces_access_control(): void
    {
        $auth = $this->createGraph(prefix: 'auth-');
        $this->getJson('/api/v1/tree-observations/'.$auth['tree_id'])->assertUnauthorized();

        $missing = $this->createGraph(prefix: 'missing-', permission: false);
        $this->app['auth']->forgetGuards();
        $this->withToken($missing['token'])->getJson('/api/v1/tree-observations/'.$missing['tree_id'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'results.read');

        $inactive = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor_id'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->withToken($inactive['token'])->getJson('/api/v1/tree-observations/'.$inactive['tree_id'])
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [TREE-02] Detail reads share the authenticated request budget.
    public function test_it_rate_limits_tree_detail_reads(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $this->withToken($graph['token'])->getJson('/api/v1/tree-observations/'.$graph['tree_id'])->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/tree-observations/'.$graph['tree_id'])
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [TREE-02] Result domains and split read/worker privileges are version controlled.
    public function test_it_versions_result_constraints_and_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_065400_create_tree_result_detail_tables.php'));
        $dcl = file_get_contents(database_path('sql/dcl/035_tree_observation_detail_grants.sql'));
        $this->assertIsString($migration);
        foreach (['species_classification_confidence_check', 'canopy_height_method_check', 'age_estimation_value_check'] as $guard) {
            $this->assertStringContainsString($guard, $migration);
        }
        $this->assertIsString($dcl);
        $this->assertStringContainsString('app.age_estimations TO mangroscan_api_rw;', $dcl);
        $this->assertStringContainsString('app.age_estimations TO mangroscan_worker;', $dcl);
        $this->assertStringNotContainsString('TO mangroscan_api_rw;\nGRANT INSERT', $dcl);
        $this->assertStringNotContainsString('DELETE', $dcl);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $graph = $this->createGraph(prefix: 'constraint-');
        $this->expectException(QueryException::class);
        DB::table('species_classification_results')
            ->where('classification_result_id', $graph['final_prediction_id'])
            ->update(['confidence_score' => 1.1]);
    }

    /** @return array<string, string> */
    private function createGraph(string $prefix = '', bool $permission = true, bool $empty = false): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignActor = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => $prefix.'Tree Detail Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Tree Detail Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, $prefix.'tree-detail@example.test');
        $this->user($foreignActor, $foreignOrg, $prefix.'foreign-tree-detail@example.test');
        $role = (string) Str::uuid();
        $permissionId = DB::table('permissions')->where('permission_code', 'results.read')->value('permission_id') ?? (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Tree Detail Reader', 'role_code' => $prefix.'tree_detail_reader', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'results.read', 'permission_name' => 'Read results', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }

        $local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $foreign = $this->lineage($foreignOrg, $foreignActor, $prefix.'FOREIGN');
        $species = (string) Str::uuid();
        DB::table('mangrove_species')->insert(['species_id' => $species, 'scientific_name' => $prefix.'Rhizophora apiculata', 'common_name' => 'Bakauan lalaki', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $media = (string) Str::uuid();
        DB::table('media_assets')->insert(['media_asset_id' => $media, 'flight_session_id' => $local['flight'], 'uploaded_by_user_id' => $actor, 'file_name' => $prefix.'tree.jpg', 'file_type' => 'image', 'mime_type' => 'image/jpeg', 'file_size_bytes' => 1024, 'storage_key' => 'tree-detail/'.$media.'.jpg', 'quality_status' => 'acceptable', 'processing_status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        $run = $this->modelRun($local['mission'], $local['flight'], $actor, $media, $prefix);
        $tree = (string) Str::uuid();
        $foreignTree = (string) Str::uuid();
        $this->tree($tree, $local['mission'], $local['flight'], $empty ? null : $run, $empty ? null : $media, 'TREE-DETAIL');
        $this->tree($foreignTree, $foreign['mission'], $foreign['flight'], null, null, 'TREE-FOREIGN');

        $finalPrediction = (string) Str::uuid();
        $finalHeight = (string) Str::uuid();
        $finalAge = (string) Str::uuid();
        if (! $empty) {
            DB::table('species_classification_results')->insert([
                ['classification_result_id' => (string) Str::uuid(), 'tree_observation_id' => $tree, 'model_run_id' => $run, 'predicted_species_id' => $species, 'confidence_score' => 0.72, 'rank_no' => 1, 'classification_basis' => json_encode(['leaf_shape' => 'elliptic']), 'is_final' => false, 'created_at' => '2026-08-12T03:00:00+00:00'],
                ['classification_result_id' => $finalPrediction, 'tree_observation_id' => $tree, 'model_run_id' => $run, 'predicted_species_id' => $species, 'confidence_score' => 0.94, 'rank_no' => 2, 'classification_basis' => json_encode(['canopy_texture' => 'dense']), 'is_final' => true, 'created_at' => '2026-08-12T03:01:00+00:00'],
            ]);
            DB::table('canopy_height_estimations')->insert([
                ['height_estimation_id' => (string) Str::uuid(), 'tree_observation_id' => $tree, 'model_run_id' => $run, 'method' => 'photogrammetry', 'height_meters' => 4.2, 'height_confidence_score' => 0.8, 'is_final' => false, 'created_at' => '2026-08-12T03:00:00+00:00'],
                ['height_estimation_id' => $finalHeight, 'tree_observation_id' => $tree, 'model_run_id' => $run, 'method' => 'stereo_depth', 'height_meters' => 4.8, 'height_confidence_score' => 0.91, 'is_final' => true, 'created_at' => '2026-08-12T03:01:00+00:00'],
            ]);
            $growth = (string) Str::uuid();
            DB::table('species_growth_models')->insert(['growth_model_id' => $growth, 'species_id' => $species, 'model_name' => 'Estuarine linear v1', 'formula_type' => 'linear', 'formula_expression' => 'height_meters / 0.8', 'source_reference' => 'Field study', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('age_estimations')->insert([
                ['age_estimation_id' => (string) Str::uuid(), 'tree_observation_id' => $tree, 'growth_model_id' => $growth, 'height_estimation_id' => $finalHeight, 'estimated_age_years' => 5.8, 'min_estimated_age_years' => null, 'max_estimated_age_years' => null, 'confidence_score' => 0.7, 'assumptions' => null, 'is_final' => false, 'created_at' => '2026-08-12T03:00:00+00:00'],
                ['age_estimation_id' => $finalAge, 'tree_observation_id' => $tree, 'growth_model_id' => $growth, 'height_estimation_id' => $finalHeight, 'estimated_age_years' => 6.0, 'min_estimated_age_years' => 5.0, 'max_estimated_age_years' => 7.0, 'confidence_score' => 0.88, 'assumptions' => 'Stable estuarine growth conditions.', 'is_final' => true, 'created_at' => '2026-08-12T03:01:00+00:00'],
            ]);
        }

        return ['actor_id' => $actor, 'site_id' => $local['site'], 'tree_id' => $tree, 'foreign_tree_id' => $foreignTree, 'media_id' => $media, 'model_run_id' => $run, 'final_prediction_id' => $finalPrediction, 'final_height_id' => $finalHeight, 'final_age_id' => $finalAge, 'token' => User::query()->findOrFail($actor)->createToken($prefix.'tree-detail')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Tree', 'last_name' => 'Detail', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{site:string,mission:string,flight:string} */
    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Inspect tree detail.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => $code, 'model' => 'Test', 'serial_number' => $code.'-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);

        return ['site' => $site, 'mission' => $mission, 'flight' => $flight];
    }

    private function modelRun(string $mission, string $flight, string $actor, string $media, string $prefix): string
    {
        $service = (string) Str::uuid();
        $model = (string) Str::uuid();
        $version = (string) Str::uuid();
        $job = (string) Str::uuid();
        $run = (string) Str::uuid();
        DB::table('ai_services')->insert(['ai_service_id' => $service, 'service_name' => $prefix.'Tree AI', 'base_url' => 'https://'.($prefix === '' ? '' : rtrim($prefix, '-').'.').'tree.example.test', 'encrypted_api_key' => Crypt::encryptString('private-secret'), 'environment' => $prefix === '' ? 'production' : substr($prefix, 0, 49), 'enabled' => true, 'health_status' => 'healthy', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_models')->insert(['model_id' => $model, 'ai_service_id' => $service, 'external_model_key' => $prefix.'detector', 'model_name' => $prefix.'Tree Detector', 'model_type' => 'tree_detector', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_model_versions')->insert(['model_version_id' => $version, 'model_id' => $model, 'version_label' => 'v1', 'model_file_path' => 'private/'.$version, 'is_deployed' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('processing_jobs')->insert(['processing_job_id' => $job, 'mission_id' => $mission, 'flight_session_id' => $flight, 'job_type' => 'detection', 'job_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('model_runs')->insert(['model_run_id' => $run, 'processing_job_id' => $job, 'model_version_id' => $version, 'run_type' => 'tree_detection', 'input_media_id' => $media, 'parameters' => json_encode(['confidence' => 0.25]), 'started_at' => now(), 'completed_at' => now(), 'run_status' => 'completed', 'created_at' => now()]);

        return $run;
    }

    private function tree(string $id, string $mission, string $flight, ?string $run, ?string $media, string $code): void
    {
        $point = json_encode(['type' => 'Point', 'coordinates' => [123.3055, 9.3065]], JSON_THROW_ON_ERROR);
        DB::table('tree_observations')->insert(['tree_observation_id' => $id, 'mission_id' => $mission, 'flight_session_id' => $flight, 'model_run_id' => $run, 'source_media_id' => $media, 'tree_code' => $code, 'tree_location' => DB::getDriverName() === 'pgsql' ? DB::raw("ST_SetSRID(ST_GeomFromGeoJSON('$point'),4326)") : $point, 'detection_confidence' => 0.94, 'validation_status' => 'unvalidated', 'created_at' => now(), 'updated_at' => now()]);
    }
}
