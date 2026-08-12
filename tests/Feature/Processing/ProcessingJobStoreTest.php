<?php

namespace Tests\Feature\Processing;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class ProcessingJobStoreTest extends TestCase
{
    use RefreshDatabase;

    // [JOB-02] An authorized request durably queues the exact job contract and model runs.
    public function test_it_queues_processing_with_exact_response_and_provenance(): void
    {
        $graph = $this->createGraph();
        $response = $this->postJob($graph, 'job-create-001', [
            'job_type' => 'detection',
            'media_ids' => [$graph['media_id'], $graph['second_media_id']],
            'parameters' => ['confidence' => 0.25, 'iou' => 0.7],
        ], ['X-Request-ID' => 'req_job_02']);

        $response->assertAccepted()->assertHeader('X-Request-ID', 'req_job_02')
            ->assertJsonPath('data.job_status', 'queued')
            ->assertJsonPath('meta.request_id', 'req_job_02');
        $this->assertSame(['processing_job_id', 'job_status'], array_keys($response->json('data')));
        $jobId = $response->json('data.processing_job_id');
        $job = DB::table('processing_jobs')->where('processing_job_id', $jobId)->first();
        $this->assertNotNull($job);
        $this->assertSame($graph['mission_id'], $job->mission_id);
        $this->assertSame($graph['flight_id'], $job->flight_session_id);
        $this->assertSame('detection', $job->job_type);
        $this->assertSame($graph['actor_id'], $job->created_by);
        $summary = json_decode($job->input_summary, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $summary['media_count']);
        $expectedMediaIds = [$graph['media_id'], $graph['second_media_id']];
        sort($expectedMediaIds, SORT_STRING);
        $this->assertSame($expectedMediaIds, $summary['media_ids']);
        $this->assertSame([$graph['detector_version_id']], $summary['model_version_ids']);
        $this->assertDatabaseCount('model_runs', 2);
        $this->assertSame(
            [$graph['detector_version_id']],
            DB::table('model_runs')->where('processing_job_id', $jobId)->pluck('model_version_id')->unique()->values()->all(),
        );
        $this->assertSame(
            ['queued'],
            DB::table('media_assets')->whereIn('media_asset_id', [$graph['media_id'], $graph['second_media_id']])
                ->pluck('processing_status')->unique()->values()->all(),
        );
        $audit = DB::table('audit_logs')->where('action', 'processing.create')->first();
        $this->assertNotNull($audit);
        $this->assertSame($jobId, $audit->record_id);
        $this->assertSame('req_job_02', $audit->request_id);
    }

    // [JOB-02] Canonically equivalent retries return one combined job without duplicate effects.
    public function test_it_is_idempotent_for_equivalent_combined_requests(): void
    {
        $graph = $this->createGraph();
        $payload = [
            'job_type' => 'full_pipeline',
            'media_ids' => [$graph['second_media_id'], $graph['media_id']],
            'parameters' => ['thresholds' => ['iou' => 0.7, 'confidence' => 0.25], 'batch' => 2],
        ];
        $first = $this->postJob($graph, 'combined-retry', $payload)->assertAccepted();
        $second = $this->postJob($graph, 'combined-retry', [
            'job_type' => 'full_pipeline',
            'media_ids' => array_reverse($payload['media_ids']),
            'parameters' => ['batch' => 2, 'thresholds' => ['confidence' => 0.25, 'iou' => 0.7]],
        ])->assertAccepted();

        $second->assertJsonPath('data.processing_job_id', $first->json('data.processing_job_id'));
        $this->assertDatabaseCount('processing_jobs', 1);
        $this->assertDatabaseCount('model_runs', 4);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->postJob($graph, 'combined-retry', [
            'job_type' => 'classification', 'media_ids' => [$graph['media_id']],
        ])->assertConflict()->assertJsonPath('error.details.idempotency_key', 'combined-retry');
    }

    // [JOB-02] Mission, flight and media references are tenant-safe and hierarchy-consistent.
    public function test_it_enforces_tenant_and_resource_lineage(): void
    {
        $graph = $this->createGraph();
        foreach ([
            ['mission_id' => $graph['foreign_mission_id'], 'flight_session_id' => $graph['foreign_flight_id'], 'media_ids' => [$graph['foreign_media_id']]],
            ['mission_id' => $graph['mission_id'], 'flight_session_id' => $graph['flight_id'], 'media_ids' => [$graph['foreign_media_id']]],
            ['mission_id' => (string) Str::uuid(), 'flight_session_id' => $graph['flight_id'], 'media_ids' => [$graph['media_id']]],
        ] as $index => $references) {
            $this->postJob($graph, 'hidden-'.$index, ['job_type' => 'detection', ...$references])
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }

        $this->postJob($graph, 'wrong-mission', [
            'mission_id' => $graph['other_mission_id'],
            'flight_session_id' => $graph['flight_id'],
            'job_type' => 'detection',
            'media_ids' => [$graph['media_id']],
        ])->assertConflict()->assertJsonPath('error.details.flight_session_id', $graph['flight_id']);
        $this->assertDatabaseCount('processing_jobs', 0);
    }

    // [JOB-02] Input workflow state must be processable before anything is queued.
    public function test_it_rejects_unprocessable_media_and_flights(): void
    {
        foreach (['quality', 'processing', 'flight'] as $case) {
            $graph = $this->createGraph(prefix: $case.'-');
            $this->app['auth']->forgetGuards();
            match ($case) {
                'quality' => DB::table('media_assets')->where('media_asset_id', $graph['media_id'])->update(['quality_status' => 'rejected']),
                'processing' => DB::table('media_assets')->where('media_asset_id', $graph['media_id'])->update(['processing_status' => 'queued']),
                'flight' => DB::table('flight_sessions')->where('flight_session_id', $graph['flight_id'])->update(['flight_status' => 'flying']),
            };
            $response = $this->postJob($graph, 'state-'.$case, [
                'job_type' => 'detection', 'media_ids' => [$graph['media_id']],
            ])->assertConflict();
            $this->assertNotEmpty($response->json('error.details'));
        }
        $this->assertDatabaseCount('processing_jobs', 0);
    }

    // [JOB-02] Required deployed capabilities must belong to an enabled healthy service.
    public function test_it_returns_service_unavailable_without_required_deployed_models(): void
    {
        foreach (['missing', 'disabled', 'unhealthy'] as $case) {
            $graph = $this->createGraph(prefix: $case.'-');
            $this->app['auth']->forgetGuards();
            match ($case) {
                'missing' => DB::table('ai_model_versions')->where('model_version_id', $graph['classifier_version_id'])->update(['is_deployed' => false]),
                'disabled' => DB::table('ai_services')->where('ai_service_id', $graph['service_id'])->update(['enabled' => false]),
                'unhealthy' => DB::table('ai_services')->where('ai_service_id', $graph['service_id'])->update(['health_status' => 'unavailable']),
            };
            $this->postJob($graph, 'model-'.$case, [
                'job_type' => 'classification', 'media_ids' => [$graph['media_id']],
            ])->assertServiceUnavailable()->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
        }
        $this->assertDatabaseCount('processing_jobs', 0);
    }

    // [JOB-02] Authentication, tenant-valid permission and active identity are mandatory.
    public function test_it_enforces_access_control(): void
    {
        $auth = $this->createGraph(prefix: 'auth-');
        $this->withoutHeader('Authorization')->withHeader('Idempotency-Key', 'unauthenticated')
            ->postJson('/api/v1/processing-jobs', $this->payload($auth))->assertUnauthorized();

        $missing = $this->createGraph(prefix: 'missing-', permission: false);
        $this->app['auth']->forgetGuards();
        $this->postJob($missing, 'missing-permission')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'processing_jobs.create');

        $inactive = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor_id'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->postJob($inactive, 'inactive')->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [JOB-02] Exact request fields, identifiers and idempotency header are validated.
    public function test_it_validates_processing_requests(): void
    {
        $graph = $this->createGraph();
        $this->postJob($graph, 'invalid', [
            'mission_id' => 'nope', 'flight_session_id' => 'nope', 'job_type' => 'combined',
            'media_ids' => ['nope', 'nope'], 'parameters' => 'nope',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'mission_id', 'flight_session_id', 'job_type', 'media_ids.0', 'media_ids.1', 'parameters',
        ], 'error.details');
        $this->postJob($graph, 'oversized-parameters', [
            'parameters' => ['blob' => str_repeat('x', 65_537)],
        ])->assertUnprocessable()->assertJsonValidationErrors(['parameters'], 'error.details');
        $this->withoutHeader('Idempotency-Key')->withToken($graph['token'])
            ->postJson('/api/v1/processing-jobs', $this->payload($graph))
            ->assertBadRequest()->assertJsonPath('error.code', 'BAD_REQUEST');
    }

    // [JOB-02] Audit failure rolls back job, runs and media workflow state.
    public function test_it_rolls_back_every_side_effect_when_audit_fails(): void
    {
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(
                string $action,
                string $tableName,
                ?string $recordId,
                ?string $userId,
                ?array $oldValues,
                ?array $newValues,
                ?string $ipAddress,
                ?string $userAgent,
                ?string $requestId,
            ): AuditLog {
                throw new RuntimeException('audit unavailable');
            }
        });
        $graph = $this->createGraph();
        $this->postJob($graph, 'rollback')->assertInternalServerError();

        $this->assertDatabaseCount('processing_jobs', 0);
        $this->assertDatabaseCount('model_runs', 0);
        $this->assertDatabaseHas('media_assets', [
            'media_asset_id' => $graph['media_id'], 'processing_status' => 'pending',
        ]);
    }

    // [JOB-02] Creation uses the shared authenticated request budget.
    public function test_it_rate_limits_processing_creation(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $this->postJob($graph, 'rate-limit')->assertAccepted();
        $this->postJob($graph, 'rate-limit')->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [JOB-02] Queue provenance and least-privilege grants are version controlled.
    public function test_it_versions_model_runs_and_narrow_runtime_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_065200_add_processing_queue_infrastructure.php'));
        $dcl = file_get_contents(database_path('sql/dcl/033_processing_job_creation_grants.sql'));
        $this->assertIsString($migration);
        foreach (['idempotency_key', "Schema::create('model_runs'", 'model_runs_type_check', 'model_runs_status_check', 'model_runs_timestamps_check'] as $guard) {
            $this->assertStringContainsString($guard, $migration);
        }
        $this->assertIsString($dcl);
        foreach (['app.processing_jobs TO mangroscan_api_rw', 'app.model_runs TO mangroscan_api_rw', 'app.media_assets TO mangroscan_api_rw', 'TO mangroscan_worker', 'ai_service_encrypted_key'] as $grant) {
            $this->assertStringContainsString($grant, $dcl);
        }
        foreach (['GRANT DELETE', 'encrypted_api_key', 'TO mangroscan_report_ro;\nGRANT UPDATE'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function postJob(array $graph, string $key, array $overrides = [], array $headers = []): TestResponse
    {
        return $this->withToken($graph['token'])->withHeaders(['Idempotency-Key' => $key, ...$headers])
            ->postJson('/api/v1/processing-jobs', [...$this->payload($graph), ...$overrides]);
    }

    /** @return array<string, mixed> */
    private function payload(array $graph): array
    {
        return [
            'mission_id' => $graph['mission_id'],
            'flight_session_id' => $graph['flight_id'],
            'job_type' => 'detection',
            'media_ids' => [$graph['media_id']],
        ];
    }

    /** @return array<string, string> */
    private function createGraph(string $prefix = '', bool $permission = true): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignUser = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => $prefix.'Queue Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Queue Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, $prefix.'queue@example.test');
        $this->user($foreignUser, $foreignOrg, $prefix.'foreign-queue@example.test');
        $role = (string) Str::uuid();
        $permissionId = DB::table('permissions')->where('permission_code', 'processing_jobs.create')->value('permission_id') ?? (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Processor', 'role_code' => $prefix.'processor', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'processing_jobs.create', 'permission_name' => 'Create processing jobs', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }

        $local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $other = $this->lineage($org, $actor, $prefix.'OTHER');
        $foreign = $this->lineage($foreignOrg, $foreignUser, $prefix.'FOREIGN');
        $media = (string) Str::uuid();
        $secondMedia = (string) Str::uuid();
        $foreignMedia = (string) Str::uuid();
        $this->media($media, $local['flight'], $actor, $prefix.'media-1.jpg');
        $this->media($secondMedia, $local['flight'], $actor, $prefix.'media-2.jpg');
        $this->media($foreignMedia, $foreign['flight'], $foreignUser, $prefix.'foreign-media.jpg');

        $service = (string) Str::uuid();
        DB::table('ai_services')->insert([
            'ai_service_id' => $service, 'service_name' => $prefix.'Inference',
            'base_url' => 'https://'.($prefix === '' ? '' : rtrim($prefix, '-').'.').'queue.example.test',
            'encrypted_api_key' => Crypt::encryptString('server-secret'), 'environment' => $prefix === '' ? 'production' : substr($prefix, 0, 49),
            'enabled' => true, 'health_status' => 'healthy', 'created_by' => $actor,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $detectorVersion = $this->model($service, $actor, $prefix.'Detector', 'tree_detector');
        $classifierVersion = $this->model($service, $actor, $prefix.'Classifier', 'species_classifier');

        return [
            'actor_id' => $actor, 'mission_id' => $local['mission'], 'flight_id' => $local['flight'],
            'other_mission_id' => $other['mission'], 'foreign_mission_id' => $foreign['mission'],
            'foreign_flight_id' => $foreign['flight'], 'media_id' => $media,
            'second_media_id' => $secondMedia, 'foreign_media_id' => $foreignMedia,
            'service_id' => $service, 'detector_version_id' => $detectorVersion,
            'classifier_version_id' => $classifierVersion,
            'token' => User::query()->findOrFail($actor)->createToken($prefix.'queue')->plainTextToken,
        ];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Job', 'last_name' => 'Creator', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{mission:string,flight:string} */
    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Queue processing.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => $code, 'model' => 'Test', 'serial_number' => $code.'-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);

        return ['mission' => $mission, 'flight' => $flight];
    }

    private function media(string $id, string $flight, string $actor, string $key): void
    {
        DB::table('media_assets')->insert(['media_asset_id' => $id, 'flight_session_id' => $flight, 'uploaded_by_user_id' => $actor, 'file_name' => basename($key), 'file_type' => 'image', 'mime_type' => 'image/jpeg', 'file_size_bytes' => 1024, 'storage_key' => 'processing/'.$key, 'checksum_sha256' => hash('sha256', $key), 'quality_status' => 'acceptable', 'processing_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function model(string $service, string $actor, string $name, string $type): string
    {
        $model = (string) Str::uuid();
        $version = (string) Str::uuid();
        DB::table('ai_models')->insert(['model_id' => $model, 'ai_service_id' => $service, 'external_model_key' => Str::slug($name), 'model_name' => $name, 'model_type' => $type, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_model_versions')->insert(['model_version_id' => $version, 'model_id' => $model, 'version_label' => 'v1', 'model_file_path' => 'models/'.Str::slug($name).'/v1.bin', 'is_deployed' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $version;
    }
}
