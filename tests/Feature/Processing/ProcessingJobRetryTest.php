<?php

namespace Tests\Feature\Processing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessingJobRetryTest extends TestCase
{
    use RefreshDatabase;

    // [JOB-04] Retry creates new queued history with copied execution provenance.
    public function test_it_retries_a_failed_job_without_mutating_history(): void
    {
        $graph = $this->createGraph();
        $response = $this->retry($graph, 'retry-success', ['reason' => 'Transient inference timeout.'])
            ->assertAccepted()->assertJsonPath('data.job_status', 'queued')
            ->assertJsonPath('data.input_summary.media_ids.0', $graph['media_id']);
        $retryId = $response->json('data.processing_job_id');
        $this->assertNotSame($graph['job_id'], $retryId);
        $this->assertDatabaseHas('processing_jobs', ['processing_job_id' => $graph['job_id'], 'job_status' => 'failed']);
        $this->assertDatabaseHas('processing_jobs', ['processing_job_id' => $retryId, 'retry_of_job_id' => $graph['job_id'], 'retry_reason' => 'Transient inference timeout.', 'job_status' => 'queued']);
        $this->assertDatabaseHas('model_runs', ['processing_job_id' => $retryId, 'model_version_id' => $graph['version_id'], 'input_media_id' => $graph['media_id'], 'run_status' => 'queued']);
        $this->assertDatabaseHas('media_assets', ['media_asset_id' => $graph['media_id'], 'processing_status' => 'queued']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'processing.retry', 'record_id' => $retryId]);
        $this->assertStringNotContainsString('model_file_path', $response->getContent());
    }

    // [JOB-04] Identical retries are idempotent and changed source/reason conflicts.
    public function test_it_is_idempotent_by_actor_key_and_canonical_payload(): void
    {
        $graph = $this->createGraph();
        $first = $this->retry($graph, 'same-key', ['reason' => ' timeout '])->assertAccepted();
        $second = $this->retry($graph, 'same-key', ['reason' => 'timeout'])->assertAccepted();
        $second->assertJsonPath('data.processing_job_id', $first->json('data.processing_job_id'));
        $this->assertDatabaseCount('processing_jobs', 3);
        $this->assertDatabaseCount('audit_logs', 1);

        DB::table('media_assets')->where('media_asset_id', $graph['media_id'])->update(['processing_status' => 'failed']);
        $this->retry($graph, 'same-key', ['reason' => 'different'])
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
    }

    // [JOB-04] Only failed jobs with failed media and healthy original service can retry.
    public function test_it_enforces_retry_workflow_preconditions(): void
    {
        foreach ([
            ['job_status' => 'completed', 'expected' => 409],
            ['processing_status' => 'completed', 'expected' => 409],
            ['health_status' => 'unavailable', 'expected' => 503],
        ] as $index => $case) {
            $graph = $this->createGraph(prefix: 'precondition-'.$index.'-');
            if (isset($case['job_status'])) {
                DB::table('processing_jobs')->where('processing_job_id', $graph['job_id'])->update(['job_status' => $case['job_status']]);
            }
            if (isset($case['processing_status'])) {
                DB::table('media_assets')->where('media_asset_id', $graph['media_id'])->update(['processing_status' => $case['processing_status']]);
            }
            if (isset($case['health_status'])) {
                DB::table('ai_services')->where('ai_service_id', $graph['service_id'])->update(['health_status' => $case['health_status']]);
            }
            $this->app['auth']->forgetGuards();
            $this->retry($graph, 'precondition')->assertStatus($case['expected']);
        }
    }

    // [JOB-04] Foreign, missing and malformed source jobs are not enumerable.
    public function test_it_enforces_tenant_and_path_boundaries(): void
    {
        $graph = $this->createGraph();
        foreach ([$graph['foreign_job_id'], (string) Str::uuid(), 'not-a-uuid'] as $job) {
            $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'boundary-'.$job)
                ->postJson('/api/v1/processing-jobs/'.$job.'/retry')
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        DB::table('survey_sites')->where('site_id', $graph['site_id'])->update(['deleted_at' => now()]);
        $this->retry($graph, 'deleted-lineage')->assertNotFound();
    }

    // [JOB-04] Header/body validation and access controls are mandatory.
    public function test_it_validates_request_and_enforces_access_control(): void
    {
        $auth = $this->createGraph(prefix: 'auth-');
        $this->withoutHeader('Authorization')->withHeader('Idempotency-Key', 'auth')
            ->postJson('/api/v1/processing-jobs/'.$auth['job_id'].'/retry')->assertUnauthorized();
        $this->withToken($auth['token'])->withoutHeader('Idempotency-Key')
            ->postJson('/api/v1/processing-jobs/'.$auth['job_id'].'/retry')->assertBadRequest();
        $this->retry($auth, 'validation', ['reason' => str_repeat('x', 5001)])->assertUnprocessable();

        $missing = $this->createGraph(prefix: 'missing-', permission: false);
        $this->app['auth']->forgetGuards();
        $this->retry($missing, 'missing')->assertForbidden()->assertJsonPath('error.details.required_permission', 'processing_jobs.create');

        $inactive = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor_id'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->retry($inactive, 'inactive')->assertForbidden();
    }

    // [JOB-04] Audit failure rolls back the entire new retry workflow.
    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $graph = $this->createGraph();
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION app.block_retry_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'blocked'; END \$\$; CREATE TRIGGER block_retry_audit BEFORE INSERT ON audit_logs FOR EACH ROW WHEN (NEW.action = 'processing.retry') EXECUTE FUNCTION app.block_retry_audit()");
        } else {
            DB::statement("CREATE TRIGGER block_retry_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'processing.retry' BEGIN SELECT RAISE(ABORT, 'blocked'); END");
        }
        $this->retry($graph, 'rollback')->assertInternalServerError();
        $this->assertDatabaseCount('processing_jobs', 2);
        $this->assertDatabaseHas('media_assets', ['media_asset_id' => $graph['media_id'], 'processing_status' => 'failed']);
    }

    // [JOB-04] Retry lineage grants only the two new API insert columns.
    public function test_it_versions_retry_lineage_and_narrow_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_065600_add_processing_job_retry_lineage.php'));
        $dcl = file_get_contents(database_path('sql/dcl/037_processing_job_retry_grants.sql'));
        $this->assertIsString($migration);
        $this->assertStringContainsString('retry_of_job_id', $migration);
        $this->assertStringContainsString('retry_reason', $migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT INSERT (retry_of_job_id, retry_reason)', $dcl);
        $this->assertStringNotContainsString('UPDATE', $dcl);
        $this->assertStringNotContainsString('DELETE', $dcl);
    }

    private function retry(array $graph, string $key, array $body = [])
    {
        return $this->withToken($graph['token'])->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/processing-jobs/'.$graph['job_id'].'/retry', $body);
    }

    /** @return array<string, string> */
    private function createGraph(string $prefix = '', bool $permission = true): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignActor = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => $prefix.'Retry Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Retry Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, $prefix.'retry@example.test');
        $this->user($foreignActor, $foreignOrg, $prefix.'foreign-retry@example.test');
        $role = (string) Str::uuid();
        $permissionId = DB::table('permissions')->where('permission_code', 'processing_jobs.create')->value('permission_id') ?? (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Retry Processor', 'role_code' => $prefix.'retry_processor', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'processing_jobs.create', 'permission_name' => 'Create processing jobs', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $foreign = $this->lineage($foreignOrg, $foreignActor, $prefix.'FOREIGN');
        $media = $this->media($local['flight'], $actor, $prefix.'retry.jpg');
        $foreignMedia = $this->media($foreign['flight'], $foreignActor, $prefix.'foreign-retry.jpg');
        [$service, $version] = $this->model($actor, $prefix);
        $job = $this->job($local['mission'], $local['flight'], $actor, $media, $version);
        $foreignJob = $this->job($foreign['mission'], $foreign['flight'], $foreignActor, $foreignMedia, $version);

        return ['actor_id' => $actor, 'site_id' => $local['site'], 'job_id' => $job, 'foreign_job_id' => $foreignJob, 'media_id' => $media, 'service_id' => $service, 'version_id' => $version, 'token' => User::query()->findOrFail($actor)->createToken($prefix.'retry')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Retry', 'last_name' => 'User', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Retry job.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => $code, 'model' => 'Test', 'serial_number' => $code.'-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);

        return ['site' => $site, 'mission' => $mission, 'flight' => $flight];
    }

    private function media(string $flight, string $actor, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('media_assets')->insert(['media_asset_id' => $id, 'flight_session_id' => $flight, 'uploaded_by_user_id' => $actor, 'file_name' => $name, 'file_type' => 'image', 'mime_type' => 'image/jpeg', 'file_size_bytes' => 1024, 'storage_key' => 'retry/'.$id.'.jpg', 'quality_status' => 'acceptable', 'processing_status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function model(string $actor, string $prefix): array
    {
        $service = (string) Str::uuid();
        $model = (string) Str::uuid();
        $version = (string) Str::uuid();
        DB::table('ai_services')->insert(['ai_service_id' => $service, 'service_name' => $prefix.'Retry AI', 'base_url' => 'https://'.($prefix === '' ? '' : rtrim($prefix, '-').'.').'retry.example.test', 'encrypted_api_key' => Crypt::encryptString('secret'), 'environment' => $prefix === '' ? 'production' : substr($prefix, 0, 49), 'enabled' => true, 'health_status' => 'healthy', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_models')->insert(['model_id' => $model, 'ai_service_id' => $service, 'external_model_key' => $prefix.'detector', 'model_name' => $prefix.'Detector', 'model_type' => 'tree_detector', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_model_versions')->insert(['model_version_id' => $version, 'model_id' => $model, 'version_label' => 'v1', 'model_file_path' => 'private/'.$version, 'is_deployed' => true, 'created_at' => now(), 'updated_at' => now()]);

        return [$service, $version];
    }

    private function job(string $mission, string $flight, string $actor, string $media, string $version): string
    {
        $job = (string) Str::uuid();
        DB::table('processing_jobs')->insert(['processing_job_id' => $job, 'mission_id' => $mission, 'flight_session_id' => $flight, 'job_type' => 'detection', 'job_status' => 'failed', 'input_summary' => json_encode(['media_ids' => [$media], 'media_count' => 1]), 'error_message' => 'timeout', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('model_runs')->insert(['model_run_id' => (string) Str::uuid(), 'processing_job_id' => $job, 'model_version_id' => $version, 'run_type' => 'tree_detection', 'input_media_id' => $media, 'parameters' => json_encode(['confidence' => 0.25]), 'started_at' => now(), 'completed_at' => now(), 'run_status' => 'failed', 'created_at' => now()]);

        return $job;
    }
}
