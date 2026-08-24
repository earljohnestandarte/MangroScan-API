<?php

namespace Tests\Feature\Report;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReportUpdateTest extends TestCase
{
    use RefreshDatabase;

    // [RPT-04] Editable draft fields update partially and emit old/new audit evidence.
    public function test_it_updates_a_report_draft(): void
    {
        $graph = $this->graph();
        $response = $this->withToken($graph['token'])->withHeader('X-Request-ID', 'req_rpt_04')
            ->patchJson('/api/v1/reports/'.$graph['report'], [
                'report_title' => ' Revised Mangrove Review ', 'report_type' => ' SPECIES_REPORT ',
                'audience' => ' Science reviewers ', 'formats' => [' XLSX ', 'CSV'],
                'generated_by' => $graph['actor'], 'approved_by' => $graph['actor'],
            ]);

        $response->assertOk()->assertHeader('X-Request-ID', 'req_rpt_04')
            ->assertJsonPath('data.report_title', 'Revised Mangrove Review')
            ->assertJsonPath('data.report_type', 'species_report')
            ->assertJsonPath('data.report_status', 'draft')
            ->assertJsonPath('data.audience', 'Science reviewers')
            ->assertJsonPath('data.summary', 'Original summary.')
            ->assertJsonPath('data.formats', ['xlsx', 'csv'])
            ->assertJsonPath('data.generated_by', null)->assertJsonPath('data.approved_by', null);
        $audit = DB::table('audit_logs')->where('action', 'report.update')->sole();
        $this->assertSame($graph['report'], $audit->record_id);
        $this->assertSame('Original Report', json_decode($audit->old_values, true, 512, JSON_THROW_ON_ERROR)['report_title']);
        $this->assertSame('Revised Mangrove Review', json_decode($audit->new_values, true, 512, JSON_THROW_ON_ERROR)['report_title']);
    }

    // [RPT-04] Nullable content can be cleared while omitted fields are preserved.
    public function test_it_clears_nullable_content(): void
    {
        $graph = $this->graph(prefix: 'clear-');
        $this->withToken($graph['token'])->patchJson('/api/v1/reports/'.$graph['report'], [
            'audience' => ' ', 'summary' => null, 'formats' => null,
        ])->assertOk()->assertJsonPath('data.audience', null)
            ->assertJsonPath('data.summary', null)->assertJsonPath('data.formats', null)
            ->assertJsonPath('data.report_title', 'Original Report');
    }

    // [RPT-04] A draft can be archived once; every non-draft state is immutable here.
    public function test_it_archives_a_draft_and_rejects_non_draft_edits(): void
    {
        $graph = $this->graph(prefix: 'states-');
        $path = '/api/v1/reports/'.$graph['report'];
        $this->withToken($graph['token'])->patchJson($path, ['report_status' => ' ARCHIVED '])
            ->assertOk()->assertJsonPath('data.report_status', 'archived');
        $this->withToken($graph['token'])->patchJson($path, ['summary' => 'Too late'])
            ->assertConflict()->assertJsonPath('error.details.report_status', 'archived');

        foreach (['generated', 'approved'] as $status) {
            $report = (string) Str::uuid();
            $this->report($report, $graph['mission'], $graph['site'], $status, $graph['actor']);
            $this->withToken($graph['token'])->patchJson('/api/v1/reports/'.$report, ['summary' => 'Not editable'])
                ->assertConflict()->assertJsonPath('error.details.report_status', $status);
        }
    }

    // [RPT-04] Missing, malformed, foreign, inconsistent, and deleted lineage is non-enumerable.
    public function test_it_hides_unavailable_reports(): void
    {
        $graph = $this->graph(prefix: 'scope-');
        foreach (['bad', (string) Str::uuid(), $graph['foreign_report'], $graph['inconsistent_report']] as $id) {
            $this->withToken($graph['token'])->patchJson('/api/v1/reports/'.$id, ['summary' => 'Scoped'])
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        DB::table('survey_missions')->where('mission_id', $graph['mission'])->update(['deleted_at' => now()]);
        $this->withToken($graph['token'])->patchJson('/api/v1/reports/'.$graph['report'], ['summary' => 'Deleted'])
            ->assertNotFound();
    }

    // [RPT-04] Empty, invalid, oversized, and generation-owned changes fail validation.
    public function test_it_validates_editable_fields(): void
    {
        $graph = $this->graph(prefix: 'validation-');
        $path = '/api/v1/reports/'.$graph['report'];
        $this->withToken($graph['token'])->patchJson($path, [])->assertUnprocessable()
            ->assertJsonValidationErrors(['request'], 'error.details');
        $this->withToken($graph['token'])->patchJson($path, [
            'report_title' => ' ', 'report_type' => 'combined', 'report_status' => 'generated',
            'audience' => str_repeat('a', 2001), 'summary' => str_repeat('s', 20001),
            'formats' => ['PDF', 'pdf', 'docx'],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'report_title', 'report_type', 'report_status', 'audience', 'summary', 'formats.1', 'formats.2',
        ], 'error.details');
        $this->withToken($graph['token'])->patchJson($path, ['generated_by' => $graph['actor']])
            ->assertUnprocessable()->assertJsonValidationErrors(['request'], 'error.details');
    }

    // [RPT-04] Authentication and a tenant-valid reports.create grant are mandatory.
    public function test_it_enforces_access_controls(): void
    {
        $anonymous = $this->graph(prefix: 'anonymous-');
        $this->patchJson('/api/v1/reports/'.$anonymous['report'], ['summary' => 'No'])->assertUnauthorized();
        $missing = $this->graph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->patchJson('/api/v1/reports/'.$missing['report'], ['summary' => 'No'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'reports.create');
        $foreign = $this->graph(foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->patchJson('/api/v1/reports/'.$foreign['report'], ['summary' => 'No'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'reports.create');
    }

    // [RPT-04] Inactive identities are rejected before report locking.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor'])->update(['status' => 'inactive']);
        $this->withToken($graph['token'])->patchJson('/api/v1/reports/'.$graph['report'], ['summary' => 'No'])
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [RPT-04] Mandatory audit failure rolls content and state back.
    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $graph = $this->graph(prefix: 'rollback-');
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($graph['token'])->patchJson('/api/v1/reports/'.$graph['report'], [
            'summary' => 'Changed', 'report_status' => 'archived',
        ])->assertInternalServerError();
        $this->assertDatabaseHas('reports', ['report_id' => $graph['report'], 'summary' => 'Original summary.', 'report_status' => 'draft']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RPT-04] Authenticated throttling limits repeated updates.
    public function test_it_rate_limits_updates(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->graph(prefix: 'limited-');
        $path = '/api/v1/reports/'.$graph['report'];
        $this->withToken($graph['token'])->patchJson($path, ['summary' => 'First'])->assertOk();
        $this->withToken($graph['token'])->patchJson($path, ['summary' => 'Second'])->assertTooManyRequests();
        $this->assertDatabaseHas('reports', ['report_id' => $graph['report'], 'summary' => 'First']);
    }

    // [RPT-04] The route and DCL expose only editable report columns.
    public function test_it_registers_the_route_and_column_limited_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/reports/{report}'
            && in_array('PATCH', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:reports.create', $route->gatherMiddleware());
        $this->assertContains('throttle:auth.authenticated', $route->gatherMiddleware());
        $dcl = file_get_contents(database_path('sql/dcl/056_report_update_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT UPDATE (', $dcl);
        $this->assertStringContainsString('ON TABLE app.reports TO mangroscan_api_rw;', $dcl);
        foreach (['generated_by', 'approved_by', 'mission_id', 'site_id', 'GRANT DELETE', 'mangroscan_report_ro', 'mangroscan_worker'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    /** @return array<string, string> */
    private function graph(bool $permission = true, bool $foreignPermission = false, string $prefix = '', string $status = 'draft'): array
    {
        $ids = [];
        foreach (['organization', 'foreign_organization', 'actor', 'foreign_actor', 'site', 'foreign_site', 'mission', 'foreign_mission', 'report', 'foreign_report', 'inconsistent_report'] as $key) {
            $ids[$key] = (string) Str::uuid();
        }
        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization'], 'organization_name' => $prefix.'Report Update Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $ids['foreign_organization'], 'organization_name' => $prefix.'Foreign Report Update Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($ids['actor'], $ids['organization'], $prefix.'report-editor@example.test');
        $this->user($ids['foreign_actor'], $ids['foreign_organization'], $prefix.'foreign-report-editor@example.test');
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
            ['role_id' => $localRole, 'organization_id' => $ids['organization'], 'role_name' => $prefix.'Report Editor', 'role_code' => $prefix.'report_editor', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $ids['foreign_organization'], 'role_name' => $prefix.'Foreign Report Editor', 'role_code' => $prefix.'foreign_report_editor', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'reports.create')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'reports.create', 'permission_name' => 'Create reports', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission || $foreignPermission) {
            $role = $foreignPermission ? $foreignRole : $localRole;
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $ids['actor'], 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        /** @var User $actor */
        $actor = User::query()->findOrFail($ids['actor']);

        return $ids + ['token' => $actor->createToken($prefix.'report-update')->plainTextToken];
    }

    private function report(string $id, string $mission, string $site, string $status, string $actor): void
    {
        DB::table('reports')->insert(['report_id' => $id, 'mission_id' => $mission, 'site_id' => $site, 'report_title' => 'Original Report', 'report_type' => 'validation_report', 'report_status' => $status, 'audience' => 'Original audience', 'summary' => 'Original summary.', 'interpretation' => 'Original interpretation.', 'limitations' => 'Original limitations.', 'recommendations' => 'Original recommendations.', 'formats' => json_encode(['pdf']), 'generated_by' => in_array($status, ['generated', 'approved'], true) ? $actor : null, 'approved_by' => $status === 'approved' ? $actor : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(string $id, string $organization, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organization, 'first_name' => 'Report', 'last_name' => 'Editor', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $organization, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organization, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $site, string $actor, string $code): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $site, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Edit report evidence.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }
}
