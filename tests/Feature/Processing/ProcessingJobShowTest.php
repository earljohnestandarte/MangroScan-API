<?php

namespace Tests\Feature\Processing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessingJobShowTest extends TestCase
{
    use RefreshDatabase;

    // [JOB-03] Detail returns the exact nested job, ordered runs and output contract.
    public function test_it_shows_job_runs_output_and_error_evidence(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])->withHeader('X-Request-ID', 'req_job_03')
            ->getJson('/api/v1/processing-jobs/'.$graph['job_id']);

        $response->assertOk()->assertHeader('X-Request-ID', 'req_job_03')
            ->assertJsonPath('meta.request_id', 'req_job_03')
            ->assertJsonPath('data.job.processing_job_id', $graph['job_id'])
            ->assertJsonPath('data.job.job_status', 'completed')
            ->assertJsonPath('data.job.error_message', null)
            ->assertJsonPath('data.model_runs.0.model_run_id', $graph['detector_run_id'])
            ->assertJsonPath('data.model_runs.0.run_type', 'tree_detection')
            ->assertJsonPath('data.model_runs.0.parameters.confidence', 0.25)
            ->assertJsonPath('data.model_runs.1.model_run_id', $graph['classifier_run_id'])
            ->assertJsonPath('data.model_runs.1.run_status', 'completed')
            ->assertJsonPath('data.output_summary.detections', 12);
        $this->assertSame(['job', 'model_runs', 'output_summary'], array_keys($response->json('data')));
        $this->assertSame([
            'model_run_id', 'processing_job_id', 'model_version_id', 'run_type',
            'input_media_id', 'parameters', 'started_at', 'completed_at',
            'run_status', 'created_at',
        ], array_keys($response->json('data.model_runs.0')));
        $this->assertStringNotContainsString('model_file_path', $response->getContent());
        $this->assertStringNotContainsString('encrypted_api_key', $response->getContent());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [JOB-03] Queued jobs can have no runs or output without changing the envelope.
    public function test_it_returns_empty_runs_and_null_output(): void
    {
        $graph = $this->createGraph(emptyJob: true);
        $this->withToken($graph['token'])->getJson('/api/v1/processing-jobs/'.$graph['job_id'])
            ->assertOk()->assertJsonPath('data.job.job_status', 'queued')
            ->assertJsonPath('data.model_runs', [])
            ->assertJsonPath('data.output_summary', null);
    }

    // [JOB-03] Foreign, missing, malformed and soft-deleted-lineage jobs remain hidden.
    public function test_it_enforces_tenant_and_path_boundaries(): void
    {
        $graph = $this->createGraph();
        foreach ([$graph['foreign_job_id'], (string) Str::uuid(), 'not-a-uuid'] as $jobId) {
            $this->withToken($graph['token'])->getJson('/api/v1/processing-jobs/'.$jobId)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }

        DB::table('survey_sites')->where('site_id', $graph['site_id'])->update(['deleted_at' => now()]);
        $this->withToken($graph['token'])->getJson('/api/v1/processing-jobs/'.$graph['job_id'])
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // [JOB-03] Authentication and tenant-valid processing management are mandatory.
    public function test_it_enforces_authentication_permission_and_active_identity(): void
    {
        $auth = $this->createGraph(prefix: 'auth-');
        $this->getJson('/api/v1/processing-jobs/'.$auth['job_id'])->assertUnauthorized();

        $missing = $this->createGraph(prefix: 'missing-', permission: false);
        $this->app['auth']->forgetGuards();
        $this->withToken($missing['token'])->getJson('/api/v1/processing-jobs/'.$missing['job_id'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'processing_jobs.manage');

        $inactive = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor_id'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->withToken($inactive['token'])->getJson('/api/v1/processing-jobs/'.$inactive['job_id'])
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [JOB-03] Detail reads use the shared authenticated request budget.
    public function test_it_rate_limits_job_detail_reads(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $this->withToken($graph['token'])->getJson('/api/v1/processing-jobs/'.$graph['job_id'])->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/processing-jobs/'.$graph['job_id'])
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [JOB-03] Detail reuses the existing read-only model-run DCL.
    public function test_it_reuses_read_only_model_run_privileges(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/033_processing_job_creation_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.model_runs TO mangroscan_api_rw, mangroscan_report_ro;', $dcl);
        $this->assertStringNotContainsString('model_file_path', $dcl);
        $this->assertStringNotContainsString('encrypted_api_key', $dcl);
    }

    /** @return array<string, string> */
    private function createGraph(string $prefix = '', bool $permission = true, bool $emptyJob = false): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignUser = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => $prefix.'Job Detail Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Job Detail Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, $prefix.'job-detail@example.test');
        $this->user($foreignUser, $foreignOrg, $prefix.'foreign-job-detail@example.test');
        $role = (string) Str::uuid();
        $permissionId = DB::table('permissions')->where('permission_code', 'processing_jobs.manage')->value('permission_id') ?? (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Job Reader', 'role_code' => $prefix.'job_reader', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'processing_jobs.manage', 'permission_name' => 'Manage processing jobs', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $foreign = $this->lineage($foreignOrg, $foreignUser, $prefix.'FOREIGN');
        $media = (string) Str::uuid();
        $foreignMedia = (string) Str::uuid();
        $this->media($media, $local['flight'], $actor, $prefix.'detail.jpg');
        $this->media($foreignMedia, $foreign['flight'], $foreignUser, $prefix.'foreign-detail.jpg');
        [$detectorVersion, $classifierVersion] = $this->models($actor, $prefix);
        $job = (string) Str::uuid();
        $foreignJob = (string) Str::uuid();
        $this->job($job, $local['mission'], $local['flight'], $actor, $emptyJob);
        $this->job($foreignJob, $foreign['mission'], $foreign['flight'], $foreignUser, true);
        $detectorRun = (string) Str::uuid();
        $classifierRun = (string) Str::uuid();
        if (! $emptyJob) {
            $this->modelRun($detectorRun, $job, $detectorVersion, $media, 'tree_detection', '2026-08-12T03:00:00+00:00');
            $this->modelRun($classifierRun, $job, $classifierVersion, $media, 'species_classification', '2026-08-12T03:01:00+00:00');
        }

        return [
            'actor_id' => $actor, 'site_id' => $local['site'], 'job_id' => $job,
            'foreign_job_id' => $foreignJob, 'detector_run_id' => $detectorRun,
            'classifier_run_id' => $classifierRun,
            'token' => User::query()->findOrFail($actor)->createToken($prefix.'job-detail')->plainTextToken,
        ];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Job', 'last_name' => 'Detail', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{site:string,mission:string,flight:string} */
    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Inspect job.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => $code, 'model' => 'Test', 'serial_number' => $code.'-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);

        return ['site' => $site, 'mission' => $mission, 'flight' => $flight];
    }

    private function media(string $id, string $flight, string $actor, string $name): void
    {
        DB::table('media_assets')->insert(['media_asset_id' => $id, 'flight_session_id' => $flight, 'uploaded_by_user_id' => $actor, 'file_name' => $name, 'file_type' => 'image', 'mime_type' => 'image/jpeg', 'file_size_bytes' => 512, 'storage_key' => 'detail/'.$id.'.jpg', 'quality_status' => 'acceptable', 'processing_status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{string,string} */
    private function models(string $actor, string $prefix): array
    {
        $service = (string) Str::uuid();
        DB::table('ai_services')->insert(['ai_service_id' => $service, 'service_name' => $prefix.'Detail AI', 'base_url' => 'https://'.($prefix === '' ? '' : rtrim($prefix, '-').'.').'detail.example.test', 'encrypted_api_key' => Crypt::encryptString('secret'), 'environment' => $prefix === '' ? 'production' : substr($prefix, 0, 49), 'enabled' => true, 'health_status' => 'healthy', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        $versions = [];
        foreach (['tree_detector', 'species_classifier'] as $type) {
            $model = (string) Str::uuid();
            $version = (string) Str::uuid();
            DB::table('ai_models')->insert(['model_id' => $model, 'ai_service_id' => $service, 'external_model_key' => $prefix.$type, 'model_name' => $prefix.$type, 'model_type' => $type, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ai_model_versions')->insert(['model_version_id' => $version, 'model_id' => $model, 'version_label' => 'v1', 'model_file_path' => 'private/'.$version, 'is_deployed' => true, 'created_at' => now(), 'updated_at' => now()]);
            $versions[] = $version;
        }

        return $versions;
    }

    private function job(string $id, string $mission, string $flight, string $actor, bool $empty): void
    {
        DB::table('processing_jobs')->insert(['processing_job_id' => $id, 'mission_id' => $mission, 'flight_session_id' => $flight, 'job_type' => 'full_pipeline', 'job_status' => $empty ? 'queued' : 'completed', 'input_summary' => json_encode(['media_count' => 1], JSON_THROW_ON_ERROR), 'output_summary' => $empty ? null : json_encode(['detections' => 12, 'classified' => 10], JSON_THROW_ON_ERROR), 'started_at' => $empty ? null : '2026-08-12T03:00:00+00:00', 'completed_at' => $empty ? null : '2026-08-12T03:02:00+00:00', 'created_by' => $actor, 'created_at' => '2026-08-12T02:59:00+00:00', 'updated_at' => '2026-08-12T03:02:00+00:00']);
    }

    private function modelRun(string $id, string $job, string $version, string $media, string $type, string $created): void
    {
        DB::table('model_runs')->insert(['model_run_id' => $id, 'processing_job_id' => $job, 'model_version_id' => $version, 'run_type' => $type, 'input_media_id' => $media, 'parameters' => json_encode(['confidence' => 0.25], JSON_THROW_ON_ERROR), 'started_at' => $created, 'completed_at' => $created, 'run_status' => 'completed', 'created_at' => $created]);
    }
}
