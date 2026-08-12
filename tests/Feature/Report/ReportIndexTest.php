<?php

namespace Tests\Feature\Report;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportIndexTest extends TestCase
{
    use RefreshDatabase;

    // [RPT-01] Tenant reports use the exact registry resource and page envelope.
    public function test_it_lists_tenant_reports_with_exact_safe_fields(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_rpt_01')
            ->getJson('/api/v1/reports?per_page=2&page=1');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_rpt_01')
            ->assertJsonPath('meta', [
                'request_id' => 'req_rpt_01',
                'page' => 1,
                'per_page' => 2,
                'total' => 3,
                'last_page' => 2,
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.report_id', $graph['latest_report_id'])
            ->assertJsonPath('data.0.report_type', 'species_report')
            ->assertJsonPath('data.0.report_status', 'approved')
            ->assertJsonPath('data.0.summary', 'Approved species evidence.')
            ->assertJsonPath('data.0.created_at', '2026-08-12T03:00:00+00:00');

        $this->assertSame([
            'report_id', 'mission_id', 'site_id', 'report_title', 'report_type',
            'report_status', 'generated_by', 'approved_by', 'summary',
            'created_at', 'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertNotContains($graph['foreign_report_id'], $response->json('data.*.report_id'));
        $this->assertNotContains($graph['inconsistent_report_id'], $response->json('data.*.report_id'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RPT-01] All documented filters compose after normalization.
    public function test_it_filters_tenant_reports(): void
    {
        $graph = $this->createGraph();
        $query = http_build_query([
            'mission_id' => strtoupper($graph['mission_id']),
            'site_id' => strtoupper($graph['site_id']),
            'status' => ' APPROVED ',
            'type' => ' SPECIES_REPORT ',
        ]);

        $this->withToken($graph['token'])->getJson('/api/v1/reports?'.$query)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.report_id', $graph['latest_report_id']);
    }

    // [RPT-01] Foreign and missing filter targets remain non-enumerable.
    public function test_it_hides_unavailable_filter_targets(): void
    {
        $graph = $this->createGraph();

        foreach ([
            'site_id='.$graph['foreign_site_id'],
            'mission_id='.$graph['foreign_mission_id'],
            'site_id='.(string) Str::uuid(),
            'mission_id='.(string) Str::uuid(),
        ] as $query) {
            $this->withToken($graph['token'])->getJson('/api/v1/reports?'.$query)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    // [RPT-01] Invalid filters fail before tenant target resolution.
    public function test_it_validates_report_filters(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->getJson('/api/v1/reports?mission_id=bad&site_id=bad&status=ready&type=combined&page=0&per_page=101')
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(
                ['mission_id', 'site_id', 'status', 'type', 'page', 'per_page'],
                'error.details',
            );
    }

    // [RPT-01] Authentication and a current/global reports.read grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $this->getJson('/api/v1/reports')->assertUnauthorized();

        $missing = $this->createGraph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->getJson('/api/v1/reports')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'reports.read');

        $foreign = $this->createGraph(foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->getJson('/api/v1/reports')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'reports.read');
    }

    // [RPT-01] Inactive identities cannot inspect report metadata.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($graph['token'])->getJson('/api/v1/reports')->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [RPT-01] Report registry reads use the shared authenticated request budget.
    public function test_it_rate_limits_report_lists(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->getJson('/api/v1/reports')->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/reports')->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [RPT-01] The authoritative domains, indexes and read-only DCL are versioned.
    public function test_it_versions_report_schema_and_read_only_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_064500_create_reports_table.php'));
        $dcl = file_get_contents(database_path('sql/dcl/025_report_grants.sql'));

        $this->assertIsString($migration);
        foreach (['reports_type_check', 'reports_status_check'] as $constraint) {
            $this->assertStringContainsString($constraint, $migration);
        }
        foreach (["['mission_id', 'report_status']", "['site_id', 'report_status']", "['report_type', 'created_at']"] as $index) {
            $this->assertStringContainsString($index, $migration);
        }
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.reports TO mangroscan_api_rw, mangroscan_report_ro;', $dcl);
        foreach (['INSERT', 'UPDATE', 'DELETE', 'mangroscan_worker', 'mangroscan_auditor'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    // [RPT-01] PostgreSQL rejects report values outside documented domains.
    public function test_postgresql_enforces_report_domains(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL report-domain verification.');
        }

        $graph = $this->createGraph(prefix: 'constraint-');
        $this->expectException(QueryException::class);
        DB::table('reports')->where('report_id', $graph['latest_report_id'])
            ->update(['report_status' => 'published']);
    }

    /** @return array<string, string> */
    private function createGraph(
        bool $permission = true,
        bool $foreignPermission = false,
        string $prefix = '',
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Reports Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Reports Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actorId, $organizationId, $prefix.'report-reader@example.test');
        $this->user($foreignUserId, $foreignOrganizationId, $prefix.'foreign-report-reader@example.test');

        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRoleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Report Reader', 'role_code' => $prefix.'report_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Report Reader', 'role_code' => $prefix.'foreign_report_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'reports.read')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore([
            'permission_id' => $permissionId, 'permission_code' => 'reports.read',
            'permission_name' => 'Read reports', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($permission || $foreignPermission) {
            $roleId = $foreignPermission ? $foreignRoleId : $localRoleId;
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        }

        $siteId = (string) Str::uuid();
        $foreignSiteId = (string) Str::uuid();
        $this->site($siteId, $organizationId, $actorId, $prefix.'RPT-SITE');
        $this->site($foreignSiteId, $foreignOrganizationId, $foreignUserId, $prefix.'FOREIGN-RPT-SITE');
        $missionId = (string) Str::uuid();
        $foreignMissionId = (string) Str::uuid();
        $this->mission($missionId, $siteId, $actorId, $prefix.'RPT-MISSION');
        $this->mission($foreignMissionId, $foreignSiteId, $foreignUserId, $prefix.'FOREIGN-RPT-MISSION');

        $latestReportId = (string) Str::uuid();
        $foreignReportId = (string) Str::uuid();
        $inconsistentReportId = (string) Str::uuid();
        $this->report((string) Str::uuid(), $missionId, $siteId, $actorId, 'monitoring_summary', 'draft', '2026-08-12T01:00:00+00:00');
        $this->report((string) Str::uuid(), $missionId, $siteId, $actorId, 'validation_report', 'generated', '2026-08-12T02:00:00+00:00');
        $this->report($latestReportId, $missionId, $siteId, $actorId, 'species_report', 'approved', '2026-08-12T03:00:00+00:00', true);
        $this->report($foreignReportId, $foreignMissionId, $foreignSiteId, $foreignUserId, 'monitoring_summary', 'draft', '2026-08-12T04:00:00+00:00');
        $this->report($inconsistentReportId, $foreignMissionId, $siteId, $actorId, 'monitoring_summary', 'draft', '2026-08-12T05:00:00+00:00');

        return [
            'actor_id' => $actorId,
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'report-index')->plainTextToken,
            'site_id' => $siteId,
            'foreign_site_id' => $foreignSiteId,
            'mission_id' => $missionId,
            'foreign_mission_id' => $foreignMissionId,
            'latest_report_id' => $latestReportId,
            'foreign_report_id' => $foreignReportId,
            'inconsistent_report_id' => $inconsistentReportId,
        ];
    }

    private function user(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId,
            'first_name' => 'Report', 'last_name' => 'Reader', 'email' => $email,
            'password' => Hash::make('password'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function site(string $id, string $organizationId, string $actorId, string $code): void
    {
        DB::table('survey_sites')->insert([
            'site_id' => $id, 'organization_id' => $organizationId,
            'site_name' => $code, 'site_code' => $code,
            'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City',
            'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mission(string $id, string $siteId, string $actorId, string $code): void
    {
        DB::table('survey_missions')->insert([
            'mission_id' => $id, 'site_id' => $siteId, 'mission_code' => $code,
            'mission_title' => $code, 'mission_objective' => 'Produce report evidence.',
            'mission_status' => 'completed', 'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function report(
        string $id,
        string $missionId,
        string $siteId,
        string $actorId,
        string $type,
        string $status,
        string $createdAt,
        bool $details = false,
    ): void {
        DB::table('reports')->insert([
            'report_id' => $id, 'mission_id' => $missionId, 'site_id' => $siteId,
            'report_title' => Str::headline($type), 'report_type' => $type,
            'report_status' => $status, 'generated_by' => $actorId,
            'approved_by' => $status === 'approved' ? $actorId : null,
            'summary' => $details ? 'Approved species evidence.' : null,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }
}
