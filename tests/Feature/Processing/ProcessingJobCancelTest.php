<?php

namespace Tests\Feature\Processing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsApiIdentity;
use Tests\TestCase;

class ProcessingJobCancelTest extends TestCase
{
    use BuildsApiIdentity;
    use RefreshDatabase;

    // [JOB-05] Cancellation closes queued/running work while preserving its history.
    public function test_it_cancels_a_tenant_processing_job_and_unfinished_runs(): void
    {
        $identity = $this->apiIdentity(['processing_jobs.manage']);
        $lineage = $this->missionLineage($identity['organization_id'], $identity['actor_id'], 'CANCEL');
        $jobId = $this->job($lineage['mission_id'], $lineage['flight_id'], $identity['actor_id'], 'queued');
        $runId = $this->modelRun($jobId, $identity['actor_id'], 'queued');

        $response = $this->withToken($identity['token'])->withHeader('X-Request-ID', 'req_job_05')
            ->postJson('/api/v1/processing-jobs/'.$jobId.'/cancel', ['reason' => ' No longer required. ']);

        $response->assertOk()->assertJsonPath('data.processing_job_id', $jobId)
            ->assertJsonPath('data.job_status', 'cancelled')->assertJsonPath('meta.request_id', 'req_job_05');
        $this->assertDatabaseHas('processing_jobs', [
            'processing_job_id' => $jobId, 'job_status' => 'cancelled',
            'cancelled_by' => $identity['actor_id'], 'cancellation_reason' => 'No longer required.',
        ]);
        $this->assertDatabaseHas('model_runs', ['model_run_id' => $runId, 'run_status' => 'cancelled']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'processing.cancel', 'record_id' => $jobId]);
        $this->assertSame([
            'processing_job_id', 'mission_id', 'flight_session_id', 'job_type', 'job_status',
            'input_summary', 'output_summary', 'started_at', 'completed_at', 'error_message',
            'created_by', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));
    }

    public function test_it_rejects_terminal_or_foreign_jobs(): void
    {
        $identity = $this->apiIdentity(['processing_jobs.manage'], 'local-cancel-');
        $lineage = $this->missionLineage($identity['organization_id'], $identity['actor_id'], 'LOCALCANCEL');
        $completedId = $this->job($lineage['mission_id'], $lineage['flight_id'], $identity['actor_id'], 'completed');
        $this->withToken($identity['token'])->postJson('/api/v1/processing-jobs/'.$completedId.'/cancel')
            ->assertConflict()->assertJsonPath('error.details.job_status', 'completed');

        $foreign = $this->apiIdentity([], 'foreign-cancel-');
        $foreignLineage = $this->missionLineage($foreign['organization_id'], $foreign['actor_id'], 'FOREIGNCANCEL');
        $foreignJob = $this->job($foreignLineage['mission_id'], $foreignLineage['flight_id'], $foreign['actor_id'], 'queued');
        $this->withToken($identity['token'])->postJson('/api/v1/processing-jobs/'.$foreignJob.'/cancel')
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_job_cancellation_requires_permission_and_versions_narrow_dcl(): void
    {
        $id = (string) Str::uuid();
        $this->postJson('/api/v1/processing-jobs/'.$id.'/cancel')->assertUnauthorized();
        $identity = $this->apiIdentity([], 'no-cancel-');
        $this->withToken($identity['token'])->postJson('/api/v1/processing-jobs/'.$id.'/cancel')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'processing_jobs.manage');

        $dcl = file_get_contents(database_path('sql/dcl/046_ai_lifecycle_write_grants.sql'));
        $this->assertStringContainsString('cancelled_at, cancelled_by, cancellation_reason', $dcl);
        $this->assertStringContainsString('GRANT UPDATE (run_status)', $dcl);
        $migration = file_get_contents(database_path('migrations/2026_08_12_066100_add_processing_job_cancellation.php'));
        $this->assertStringContainsString("'cancelled'", $migration);
    }

    private function job(string $missionId, string $flightId, string $actorId, string $status): string
    {
        $id = (string) Str::uuid();
        DB::table('processing_jobs')->insert([
            'processing_job_id' => $id, 'mission_id' => $missionId, 'flight_session_id' => $flightId,
            'job_type' => 'detection', 'job_status' => $status, 'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function modelRun(string $jobId, string $actorId, string $status): string
    {
        $modelId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        DB::table('ai_models')->insert([
            'model_id' => $modelId, 'model_name' => 'Cancel Detector', 'model_type' => 'tree_detector',
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ai_model_versions')->insert([
            'model_version_id' => $versionId, 'model_id' => $modelId, 'version_label' => 'v1',
            'model_file_path' => 'private/'.$versionId.'.pt', 'is_deployed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('model_runs')->insert([
            'model_run_id' => $runId, 'processing_job_id' => $jobId,
            'model_version_id' => $versionId, 'run_type' => 'tree_detection',
            'run_status' => $status, 'created_at' => now(),
        ]);

        return $runId;
    }
}
