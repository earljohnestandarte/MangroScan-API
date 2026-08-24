<?php

namespace Tests\Feature\Report;

use App\Exceptions\WorkflowConflictException;
use App\Jobs\GenerateReportArtifact;
use App\Models\ReportGenerationJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Report\ReportGenerationExecutionService;
use App\Services\Report\ReportUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReportGenerateTest extends TestCase
{
    use RefreshDatabase;

    // [RPT-05] Generation is durably queued with canonical options and audit evidence.
    public function test_it_queues_pdf_generation(): void
    {
        Queue::fake();
        $graph = $this->graph();
        $response = $this->request($graph, 'generate-report', [
            'format' => ' PDF ',
            'options' => ['page_size' => ' LETTER ', 'orientation' => ' Landscape ', 'include_source_summary' => false],
        ], 'req_rpt_05');

        $response->assertAccepted()->assertHeader('X-Request-ID', 'req_rpt_05')->assertJsonCount(1)
            ->assertJsonCount(3, 'data')->assertJsonPath('data.report_id', $graph['report'])
            ->assertJsonPath('data.status', 'queued');
        $jobId = $response->json('data.job_id');
        $job = ReportGenerationJob::query()->findOrFail($jobId);
        $this->assertSame($graph['organization'], $job->organization_id);
        $this->assertCount(3, $job->options);
        $this->assertFalse($job->options['include_source_summary']);
        $this->assertSame('landscape', $job->options['orientation']);
        $this->assertSame('letter', $job->options['page_size']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.generate.queue', 'record_id' => $jobId, 'request_id' => 'req_rpt_05']);
        Queue::assertPushed(GenerateReportArtifact::class, fn (GenerateReportArtifact $queued): bool => $queued->jobId === $jobId);
    }

    // [RPT-05] Same-key retries replay one job; changed requests conflict.
    public function test_it_is_idempotent_and_rejects_key_reuse(): void
    {
        Queue::fake();
        $graph = $this->graph(prefix: 'idempotent-');
        $payload = ['format' => 'pdf', 'options' => ['orientation' => 'portrait']];
        $first = $this->request($graph, 'same-key', $payload)->assertAccepted();
        $second = $this->request($graph, 'same-key', $payload)->assertAccepted();
        $this->assertSame($first->json('data'), $second->json('data'));
        $this->request($graph, 'same-key', ['format' => 'pdf', 'options' => ['orientation' => 'landscape']])
            ->assertConflict()->assertJsonPath('error.details.idempotency_key', 'same-key');
        $this->assertDatabaseCount('report_generation_jobs', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'report.generate.queue')->count());
        Queue::assertPushed(GenerateReportArtifact::class, 1);
    }

    // [RPT-05] A report cannot have overlapping active generation jobs.
    public function test_it_rejects_overlapping_generation(): void
    {
        Queue::fake();
        $graph = $this->graph(prefix: 'overlap-');
        $this->request($graph, 'first', ['format' => 'pdf'])->assertAccepted();
        $this->request($graph, 'second', ['format' => 'pdf'])->assertConflict()
            ->assertJsonPath('error.details.report_id', $graph['report']);
        $this->assertDatabaseCount('report_generation_jobs', 1);
    }

    // [RPT-05] An active artifact job freezes report content until it completes or fails.
    public function test_it_blocks_report_edits_while_generation_is_active(): void
    {
        Queue::fake();
        $graph = $this->graph(prefix: 'edit-race-');
        $this->request($graph, 'active', ['format' => 'pdf'])->assertAccepted();

        try {
            app(ReportUpdateService::class)->update(
                User::query()->findOrFail($graph['actor']), $graph['report'], ['summary' => 'Changed too late.'],
                null, null, null,
            );
            $this->fail('An active generation job must freeze report content.');
        } catch (WorkflowConflictException $exception) {
            $this->assertSame('Reports cannot be edited or archived while generation is active.', $exception->getMessage());
        }
        $this->assertDatabaseHas('reports', ['report_id' => $graph['report'], 'summary' => 'Canonical monitoring results.']);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'report.update')->count());
    }

    // [RPT-05] Only current drafts can enter generation.
    public function test_it_rejects_non_draft_reports(): void
    {
        Queue::fake();
        $graph = $this->graph(prefix: 'states-', status: 'generated');
        foreach (['generated', 'approved', 'archived'] as $status) {
            $report = $status === 'generated' ? $graph['report'] : (string) Str::uuid();
            if ($status !== 'generated') {
                $this->report($report, $graph['mission'], $graph['site'], $status, $graph['actor']);
            }
            $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'generate-'.$status)
                ->postJson('/api/v1/reports/'.$report.'/generate', ['format' => 'pdf'])->assertConflict()
                ->assertJsonPath('error.details.report_status', $status);
        }
        Queue::assertNothingPushed();
    }

    // [RPT-05] Format, bounded options, and Idempotency-Key are mandatory.
    public function test_it_validates_generation_requests(): void
    {
        Queue::fake();
        $graph = $this->graph(prefix: 'validation-');
        $this->request($graph, '', ['format' => 'pdf'])->assertBadRequest()
            ->assertJsonPath('error.code', 'BAD_REQUEST');
        $this->request($graph, str_repeat('k', 101), ['format' => 'pdf'])->assertBadRequest();
        $this->request($graph, 'invalid', [
            'format' => 'docx',
            'options' => ['page_size' => 'legal', 'orientation' => 'square', 'include_source_summary' => 'yes', 'unknown' => true],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'format', 'options', 'options.page_size', 'options.orientation', 'options.include_source_summary',
        ], 'error.details');
    }

    // [RPT-05] Missing, malformed, foreign, inconsistent, and deleted reports are hidden.
    public function test_it_hides_unavailable_reports(): void
    {
        Queue::fake();
        $graph = $this->graph(prefix: 'scope-');
        foreach (['bad', (string) Str::uuid(), $graph['foreign_report'], $graph['inconsistent_report']] as $id) {
            $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'scope-'.$id)
                ->postJson('/api/v1/reports/'.$id.'/generate', ['format' => 'pdf'])->assertNotFound();
        }
        DB::table('survey_missions')->where('mission_id', $graph['mission'])->update(['deleted_at' => now()]);
        $this->request($graph, 'deleted', ['format' => 'pdf'])->assertNotFound();
        Queue::assertNothingPushed();
    }

    // [RPT-05] Authentication and a local permission are mandatory.
    public function test_it_enforces_access_controls(): void
    {
        Queue::fake();
        $anonymous = $this->graph(prefix: 'anonymous-');
        $this->withHeader('Idempotency-Key', 'anonymous')->postJson('/api/v1/reports/'.$anonymous['report'].'/generate', ['format' => 'pdf'])->assertUnauthorized();
        $missing = $this->graph(permission: false, prefix: 'missing-');
        $this->request($missing, 'missing', ['format' => 'pdf'])->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'reports.generate');
        $foreign = $this->graph(foreignPermission: true, prefix: 'foreign-role-');
        $this->request($foreign, 'foreign', ['format' => 'pdf'])->assertForbidden();
    }

    // [RPT-05] Inactive identities are rejected before report locking.
    public function test_it_rejects_an_inactive_identity(): void
    {
        Queue::fake();
        $graph = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor'])->update(['status' => 'inactive']);
        $this->request($graph, 'inactive', ['format' => 'pdf'])->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [RPT-05] Queue audit failure rolls the ledger back and dispatches nothing.
    public function test_it_rolls_back_when_queue_audit_fails(): void
    {
        Queue::fake();
        $graph = $this->graph(prefix: 'rollback-');
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->request($graph, 'rollback', ['format' => 'pdf'])->assertInternalServerError();
        $this->assertDatabaseCount('report_generation_jobs', 0);
        Queue::assertNothingPushed();
    }

    // [RPT-05] The queued worker writes a private valid PDF and advances state atomically.
    public function test_the_worker_completes_a_private_pdf_artifact(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['mangroscan.media.disk' => 'local']);
        $graph = $this->graph(prefix: 'worker-');
        $response = $this->request($graph, 'worker-job', ['format' => 'pdf']);
        $jobId = $response->json('data.job_id');

        (new GenerateReportArtifact($jobId))->handle(app(ReportGenerationExecutionService::class));

        $job = ReportGenerationJob::query()->findOrFail($jobId);
        $this->assertSame('completed', $job->job_status);
        $this->assertNotNull($job->storage_key);
        Storage::disk('local')->assertExists($job->storage_key);
        $bytes = Storage::disk('local')->get($job->storage_key);
        $this->assertStringStartsWith('%PDF-1.4', $bytes);
        $this->assertSame(strlen($bytes), $job->file_size_bytes);
        $this->assertSame(hash('sha256', $bytes), $job->checksum_sha256);
        $this->assertDatabaseHas('reports', ['report_id' => $graph['report'], 'report_status' => 'generated', 'generated_by' => $graph['actor']]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'report.generate.complete')->count());
    }

    // [RPT-05] Authenticated throttling limits repeated queue attempts.
    public function test_it_rate_limits_generation(): void
    {
        Queue::fake();
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->graph(prefix: 'limited-');
        $this->request($graph, 'first', ['format' => 'pdf'])->assertAccepted();
        $this->request($graph, 'second', ['format' => 'pdf'])->assertTooManyRequests();
        $this->assertDatabaseCount('report_generation_jobs', 1);
    }

    // [RPT-05] The route, job invariants, queue worker, and narrow role grants are versioned.
    public function test_it_versions_route_schema_job_and_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/reports/{report}/generate'
            && in_array('POST', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:reports.generate', $route->gatherMiddleware());
        $migration = file_get_contents(database_path('migrations/2026_08_25_000500_create_report_generation_jobs_table.php'));
        $dcl = file_get_contents(database_path('sql/dcl/057_report_generation_grants.sql'));
        $worker = file_get_contents(app_path('Jobs/GenerateReportArtifact.php'));
        foreach (['report_generation_jobs_format_check', 'report_generation_jobs_status_check', 'report_generation_jobs_artifact_check', 'report_generation_jobs_one_active_per_report', 'trg_report_generation_jobs_touch_updated_at', "['created_by', 'idempotency_key']"] as $fragment) {
            $this->assertStringContainsString($fragment, $migration);
        }
        $this->assertStringContainsString('implements ShouldQueue', $worker);
        $this->assertStringContainsString('GRANT INSERT (', $dcl);
        $this->assertStringContainsString('TO mangroscan_api_rw;', $dcl);
        $this->assertStringContainsString('TO mangroscan_worker;', $dcl);
        $this->assertStringContainsString('GRANT UPDATE (report_status, generated_by, updated_at) ON TABLE app.reports TO mangroscan_worker;', $dcl);
        $this->assertStringNotContainsString('approved_by', $dcl);
        $this->assertStringNotContainsString('GRANT DELETE', $dcl);
    }

    /** @param array<string, string> $graph
     * @param  array<string, mixed>  $payload
     */
    private function request(array $graph, string $key, array $payload, ?string $requestId = null): TestResponse
    {
        $request = $this->withToken($graph['token'])->withHeader('Idempotency-Key', $key);
        if ($requestId !== null) {
            $request->withHeader('X-Request-ID', $requestId);
        }

        return $request->postJson('/api/v1/reports/'.$graph['report'].'/generate', $payload);
    }

    /** @return array<string, string> */
    private function graph(bool $permission = true, bool $foreignPermission = false, string $prefix = '', string $status = 'draft'): array
    {
        $ids = [];
        foreach (['organization', 'foreign_organization', 'actor', 'foreign_actor', 'site', 'foreign_site', 'mission', 'foreign_mission', 'report', 'foreign_report', 'inconsistent_report'] as $key) {
            $ids[$key] = (string) Str::uuid();
        }
        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization'], 'organization_name' => $prefix.'Report Generation Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $ids['foreign_organization'], 'organization_name' => $prefix.'Foreign Report Generation Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($ids['actor'], $ids['organization'], $prefix.'report-generator@example.test');
        $this->user($ids['foreign_actor'], $ids['foreign_organization'], $prefix.'foreign-report-generator@example.test');
        $this->site($ids['site'], $ids['organization'], $ids['actor'], $prefix.'RPT-SITE');
        $this->site($ids['foreign_site'], $ids['foreign_organization'], $ids['foreign_actor'], $prefix.'FOREIGN-RPT-SITE');
        $this->mission($ids['mission'], $ids['site'], $ids['actor'], $prefix.'RPT-MSN');
        $this->mission($ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor'], $prefix.'FOREIGN-RPT-MSN');
        $this->report($ids['report'], $ids['mission'], $ids['site'], $status, $ids['actor']);
        $this->report($ids['foreign_report'], $ids['foreign_mission'], $ids['foreign_site'], 'draft', $ids['foreign_actor']);
        $this->report($ids['inconsistent_report'], $ids['foreign_mission'], $ids['site'], 'draft', $ids['actor']);
        $localRole = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRole, 'organization_id' => $ids['organization'], 'role_name' => $prefix.'Report Generator', 'role_code' => $prefix.'report_generator', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $ids['foreign_organization'], 'role_name' => $prefix.'Foreign Report Generator', 'role_code' => $prefix.'foreign_report_generator', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'reports.generate')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'reports.generate', 'permission_name' => 'Generate reports', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission || $foreignPermission) {
            $role = $foreignPermission ? $foreignRole : $localRole;
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $ids['actor'], 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        /** @var User $actor */
        $actor = User::query()->findOrFail($ids['actor']);

        return $ids + ['token' => $actor->createToken($prefix.'report-generate')->plainTextToken];
    }

    private function report(string $id, string $mission, string $site, string $status, string $actor): void
    {
        DB::table('reports')->insert(['report_id' => $id, 'mission_id' => $mission, 'site_id' => $site, 'report_title' => 'Mangrove Monitoring Report', 'report_type' => 'monitoring_summary', 'report_status' => $status, 'audience' => 'Coastal managers', 'summary' => 'Canonical monitoring results.', 'interpretation' => 'Mangrove conditions are stable.', 'limitations' => 'Weather constrained one flight.', 'recommendations' => 'Repeat monitoring next quarter.', 'formats' => json_encode(['pdf']), 'generated_by' => in_array($status, ['generated', 'approved'], true) ? $actor : null, 'approved_by' => $status === 'approved' ? $actor : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(string $id, string $organization, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organization, 'first_name' => 'Report', 'last_name' => 'Generator', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $organization, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organization, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $site, string $actor, string $code): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $site, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Generate report evidence.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }
}
