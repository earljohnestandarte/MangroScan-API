<?php

namespace Tests\Feature\Report;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReportApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['mangroscan.media.disk' => 'local']);
    }

    // [RPT-06] Approval records the approver against verified private artifact evidence.
    public function test_it_approves_a_generated_report(): void
    {
        $graph = $this->graph();
        $response = $this->request($graph, ['decision' => ' APPROVED ', 'notes' => ' Reviewed evidence. '], 'req_rpt_06');
        $response->assertOk()->assertHeader('X-Request-ID', 'req_rpt_06')
            ->assertJsonCount(1)->assertJsonCount(16, 'data')
            ->assertJsonPath('data.report_status', 'approved')
            ->assertJsonPath('data.approved_by', $graph['actor'])
            ->assertJsonPath('data.generated_by', $graph['generator']);
        $audit = AuditLog::query()->sole();
        $this->assertSame('report.approval', $audit->action);
        $this->assertSame('approved', $audit->new_values['decision']);
        $this->assertSame('Reviewed evidence.', $audit->new_values['notes']);
        $this->assertSame($graph['job'], $audit->new_values['generation_job_id']);
        $this->assertDatabaseHas('report_generation_jobs', ['report_generation_job_id' => $graph['job'], 'job_status' => 'completed']);
    }

    // [RPT-06] Rejection returns the definition to draft and clears generation ownership for revision.
    public function test_it_rejects_a_generated_report_for_revision(): void
    {
        $graph = $this->graph(artifactExists: false, prefix: 'reject-');
        $this->request($graph, ['decision' => 'rejected', 'notes' => '   '])->assertOk()
            ->assertJsonPath('data.report_status', 'draft')
            ->assertJsonPath('data.generated_by', null)
            ->assertJsonPath('data.approved_by', null);
        $this->assertDatabaseHas('reports', [
            'report_id' => $graph['report'], 'report_status' => 'draft',
            'generated_by' => null, 'approved_by' => null,
        ]);
        $this->assertNull(AuditLog::query()->sole()->new_values['notes']);
    }

    // [RPT-06] Only generated rows backed by a completed generation job are decidable.
    public function test_it_enforces_generated_artifact_lifecycle(): void
    {
        $missingArtifact = $this->graph(artifact: false, prefix: 'no-job-');
        $this->request($missingArtifact, ['decision' => 'approved'])->assertConflict()
            ->assertJsonPath('error.details.report_id', $missingArtifact['report']);

        foreach (['draft', 'approved', 'archived'] as $status) {
            DB::table('reports')->where('report_id', $missingArtifact['report'])->update([
                'report_status' => $status,
                'generated_by' => $status === 'approved' ? $missingArtifact['generator'] : null,
                'approved_by' => $status === 'approved' ? $missingArtifact['generator'] : null,
            ]);
            $this->request($missingArtifact, ['decision' => 'approved'])->assertConflict()
                ->assertJsonPath('error.details.report_status', $status);
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RPT-06] Approval refuses a completed ledger whose private object is unavailable.
    public function test_it_requires_the_private_artifact_for_approval(): void
    {
        $graph = $this->graph(artifactExists: false, prefix: 'missing-object-');
        $this->request($graph, ['decision' => 'approved'])->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
        $this->assertDatabaseHas('reports', [
            'report_id' => $graph['report'], 'report_status' => 'generated', 'approved_by' => null,
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RPT-06] Decision and bounded optional notes are the complete mutation contract.
    public function test_it_validates_the_decision_contract(): void
    {
        $graph = $this->graph(prefix: 'validation-');
        $this->request($graph, ['decision' => 'maybe', 'notes' => str_repeat('x', 2001)])
            ->assertUnprocessable()->assertJsonValidationErrors(['decision', 'notes'], 'error.details');
        $this->request($graph, [])->assertUnprocessable()->assertJsonValidationErrors(['decision'], 'error.details');
    }

    // [RPT-06] Missing, malformed, foreign, inconsistent, and deleted report lineage is hidden.
    public function test_it_hides_unavailable_reports(): void
    {
        $graph = $this->graph(prefix: 'scope-');
        foreach (['bad', (string) Str::uuid(), $graph['foreign_report'], $graph['inconsistent_report']] as $id) {
            $this->withToken($graph['token'])->postJson('/api/v1/reports/'.$id.'/approve', ['decision' => 'approved'])
                ->assertNotFound();
        }
        DB::table('survey_missions')->where('mission_id', $graph['mission'])->update(['deleted_at' => now()]);
        $this->request($graph, ['decision' => 'approved'])->assertNotFound();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RPT-06] Authentication and a current-tenant reports.approve grant are mandatory.
    public function test_it_enforces_access_controls(): void
    {
        $anonymous = $this->graph(prefix: 'anonymous-');
        $this->postJson('/api/v1/reports/'.$anonymous['report'].'/approve', ['decision' => 'approved'])->assertUnauthorized();
        $missing = $this->graph(permission: false, prefix: 'missing-');
        $this->request($missing, ['decision' => 'approved'])->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'reports.approve');
        $foreign = $this->graph(foreignPermission: true, prefix: 'foreign-role-');
        $this->request($foreign, ['decision' => 'approved'])->assertForbidden();
    }

    // [RPT-06] Inactive identities are rejected before report locking or storage checks.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $inactive = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor'])->update(['status' => 'inactive']);
        $this->request($inactive, ['decision' => 'approved'])->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [RPT-06] Approval and its append-only decision evidence share one transaction.
    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $graph = $this->graph(prefix: 'rollback-');
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->request($graph, ['decision' => 'approved'])->assertInternalServerError();
        $this->assertDatabaseHas('reports', [
            'report_id' => $graph['report'], 'report_status' => 'generated',
            'generated_by' => $graph['generator'], 'approved_by' => null,
        ]);
    }

    // [RPT-06] Authenticated throttling limits repeated approval decisions.
    public function test_it_rate_limits_approval(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->graph(prefix: 'limited-');
        $this->request($graph, ['decision' => 'approved'])->assertOk();
        $this->request($graph, ['decision' => 'approved'])->assertTooManyRequests();
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [RPT-06] The protected route and narrow approval columns are versioned.
    public function test_it_versions_the_route_and_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/reports/{report}/approve'
            && in_array('POST', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:reports.approve', $route->gatherMiddleware());
        $dcl = file_get_contents(database_path('sql/dcl/058_report_approval_grants.sql'));
        foreach (['report_status', 'generated_by', 'approved_by', 'updated_at', 'TO mangroscan_api_rw;'] as $fragment) {
            $this->assertStringContainsString($fragment, $dcl);
        }
        $this->assertStringNotContainsString('GRANT DELETE', $dcl);
        $this->assertStringNotContainsString('mangroscan_worker', $dcl);
    }

    /** @param array<string, string> $graph
     * @param  array<string, mixed>  $payload
     */
    private function request(array $graph, array $payload, ?string $requestId = null): TestResponse
    {
        $request = $this->withToken($graph['token']);
        if ($requestId !== null) {
            $request->withHeader('X-Request-ID', $requestId);
        }

        return $request->postJson('/api/v1/reports/'.$graph['report'].'/approve', $payload);
    }

    /** @return array<string, string> */
    private function graph(
        string $status = 'generated',
        bool $artifact = true,
        bool $artifactExists = true,
        bool $permission = true,
        bool $foreignPermission = false,
        string $prefix = '',
    ): array {
        $ids = [];
        foreach (['organization', 'foreign_organization', 'actor', 'generator', 'foreign_actor', 'site', 'foreign_site', 'mission', 'foreign_mission', 'report', 'foreign_report', 'inconsistent_report', 'job'] as $key) {
            $ids[$key] = (string) Str::uuid();
        }
        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization'], 'organization_name' => $prefix.'Report Approval Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $ids['foreign_organization'], 'organization_name' => $prefix.'Foreign Approval Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($ids['actor'], $ids['organization'], $prefix.'approver@example.test');
        $this->user($ids['generator'], $ids['organization'], $prefix.'generator@example.test');
        $this->user($ids['foreign_actor'], $ids['foreign_organization'], $prefix.'foreign-approver@example.test');
        $this->site($ids['site'], $ids['organization'], $ids['generator'], $prefix.'RPT-SITE');
        $this->site($ids['foreign_site'], $ids['foreign_organization'], $ids['foreign_actor'], $prefix.'FOREIGN-RPT-SITE');
        $this->mission($ids['mission'], $ids['site'], $ids['generator'], $prefix.'RPT-MSN');
        $this->mission($ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor'], $prefix.'FOREIGN-RPT-MSN');
        $this->report($ids['report'], $ids['mission'], $ids['site'], $status, $ids['generator']);
        $this->report($ids['foreign_report'], $ids['foreign_mission'], $ids['foreign_site'], 'generated', $ids['foreign_actor']);
        $this->report($ids['inconsistent_report'], $ids['foreign_mission'], $ids['site'], 'generated', $ids['generator']);
        if ($artifact) {
            $storageKey = 'report-artifacts/'.$ids['organization'].'/'.$ids['report'].'/report.pdf';
            DB::table('report_generation_jobs')->insert([
                'report_generation_job_id' => $ids['job'], 'organization_id' => $ids['organization'],
                'report_id' => $ids['report'], 'format' => 'pdf', 'options' => json_encode([]),
                'job_status' => 'completed', 'file_name' => 'report.pdf', 'storage_key' => $storageKey,
                'file_size_bytes' => 8, 'checksum_sha256' => str_repeat('a', 64), 'created_by' => $ids['generator'],
                'idempotency_key' => $prefix.'generation', 'request_fingerprint' => str_repeat('b', 64),
                'started_at' => now()->subSecond(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($artifactExists) {
                Storage::disk('local')->put($storageKey, '%PDF-ok');
            }
        }
        $localRole = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRole, 'organization_id' => $ids['organization'], 'role_name' => $prefix.'Report Approver', 'role_code' => $prefix.'report_approver', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $ids['foreign_organization'], 'role_name' => $prefix.'Foreign Report Approver', 'role_code' => $prefix.'foreign_report_approver', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'reports.approve')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'reports.approve', 'permission_name' => 'Approve reports', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission || $foreignPermission) {
            $role = $foreignPermission ? $foreignRole : $localRole;
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $ids['actor'], 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        /** @var User $actor */
        $actor = User::query()->findOrFail($ids['actor']);

        return $ids + ['token' => $actor->createToken($prefix.'report-approval')->plainTextToken];
    }

    private function report(string $id, string $mission, string $site, string $status, string $generator): void
    {
        DB::table('reports')->insert([
            'report_id' => $id, 'mission_id' => $mission, 'site_id' => $site,
            'report_title' => 'Mangrove Monitoring Report', 'report_type' => 'monitoring_summary',
            'report_status' => $status, 'audience' => 'Coastal managers', 'summary' => 'Canonical evidence.',
            'interpretation' => 'Conditions are stable.', 'limitations' => 'Weather limited one flight.',
            'recommendations' => 'Repeat next quarter.', 'formats' => json_encode(['pdf']),
            'generated_by' => in_array($status, ['generated', 'approved'], true) ? $generator : null,
            'approved_by' => $status === 'approved' ? $generator : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(string $id, string $organization, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organization, 'first_name' => 'Report', 'last_name' => 'Reviewer', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $organization, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organization, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $site, string $actor, string $code): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $site, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Approve report evidence.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }
}
