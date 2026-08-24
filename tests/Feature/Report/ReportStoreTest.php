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

class ReportStoreTest extends TestCase
{
    use RefreshDatabase;

    // [RPT-02] A report draft persists the approved content contract and immutable audit evidence.
    public function test_it_creates_an_audited_report_draft(): void
    {
        $graph = $this->graph();
        $response = $this->withToken($graph['token'])->withHeader('X-Request-ID', 'req_rpt_02')
            ->postJson('/api/v1/reports', $this->payload($graph) + [
                'report_status' => 'approved', 'generated_by' => $graph['actor'],
            ]);

        $response->assertCreated()->assertHeader('X-Request-ID', 'req_rpt_02')->assertJsonCount(1)
            ->assertJsonCount(16, 'data')
            ->assertJsonPath('data.mission_id', $graph['mission'])
            ->assertJsonPath('data.site_id', $graph['site'])
            ->assertJsonPath('data.report_title', 'Quarterly Mangrove Review')
            ->assertJsonPath('data.report_type', 'validation_report')
            ->assertJsonPath('data.report_status', 'draft')
            ->assertJsonPath('data.audience', 'Coastal managers')
            ->assertJsonPath('data.formats', ['pdf', 'geojson'])
            ->assertJsonPath('data.generated_by', null)
            ->assertJsonPath('data.approved_by', null);
        $this->assertSame([
            'report_id', 'mission_id', 'site_id', 'report_title', 'report_type', 'report_status',
            'audience', 'summary', 'interpretation', 'limitations', 'recommendations', 'formats',
            'generated_by', 'approved_by', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));
        $this->assertDatabaseHas('reports', [
            'report_id' => $response->json('data.report_id'), 'report_status' => 'draft',
            'generated_by' => null, 'approved_by' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'report.create', 'table_name' => 'reports',
            'record_id' => $response->json('data.report_id'), 'user_id' => $graph['actor'],
            'request_id' => 'req_rpt_02',
        ]);
    }

    // [RPT-02] Optional report content remains explicitly nullable.
    public function test_it_creates_a_minimal_draft(): void
    {
        $graph = $this->graph(prefix: 'minimal-');
        $payload = $this->payload($graph);
        unset($payload['audience'], $payload['summary'], $payload['interpretation'], $payload['limitations'], $payload['recommendations'], $payload['formats']);

        $this->withToken($graph['token'])->postJson('/api/v1/reports', $payload)->assertCreated()
            ->assertJsonPath('data.audience', null)->assertJsonPath('data.summary', null)
            ->assertJsonPath('data.interpretation', null)->assertJsonPath('data.limitations', null)
            ->assertJsonPath('data.recommendations', null)->assertJsonPath('data.formats', null);
    }

    // [RPT-02] Foreign, missing, and inconsistent mission/site lineage is non-enumerable.
    public function test_it_hides_unavailable_lineage(): void
    {
        $graph = $this->graph(prefix: 'scope-');
        foreach ([
            ['site_id' => $graph['foreign_site']],
            ['mission_id' => $graph['foreign_mission']],
            ['site_id' => (string) Str::uuid()],
            ['mission_id' => (string) Str::uuid()],
            ['site_id' => $graph['other_site']],
        ] as $override) {
            $this->withToken($graph['token'])->postJson('/api/v1/reports', array_replace($this->payload($graph), $override))
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RPT-02] IDs, content limits, report type, and requested output formats are strict.
    public function test_it_validates_the_draft_contract(): void
    {
        $graph = $this->graph(prefix: 'validation-');
        $this->withToken($graph['token'])->postJson('/api/v1/reports', [
            'mission_id' => 'bad', 'site_id' => 'bad', 'report_title' => ' ',
            'report_type' => 'combined', 'audience' => str_repeat('a', 2001),
            'summary' => str_repeat('s', 20001), 'interpretation' => str_repeat('i', 20001),
            'limitations' => str_repeat('l', 20001), 'recommendations' => str_repeat('r', 20001),
            'formats' => ['PDF', 'pdf', 'docx'],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors([
                'mission_id', 'site_id', 'report_title', 'report_type', 'audience', 'summary',
                'interpretation', 'limitations', 'recommendations', 'formats.1', 'formats.2',
            ], 'error.details');
        $this->assertDatabaseCount('reports', 0);
    }

    // [RPT-02] Authentication and a current/global reports.create grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $anonymous = $this->graph(prefix: 'anonymous-');
        $this->postJson('/api/v1/reports', $this->payload($anonymous))->assertUnauthorized();

        $missing = $this->graph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->postJson('/api/v1/reports', $this->payload($missing))
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'reports.create');

        $foreign = $this->graph(foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->postJson('/api/v1/reports', $this->payload($foreign))
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'reports.create');
    }

    // [RPT-02] Inactive identities are rejected before draft creation.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor'])->update(['status' => 'inactive']);
        $this->withToken($graph['token'])->postJson('/api/v1/reports', $this->payload($graph))
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [RPT-02] Mandatory audit failure rolls the report draft back.
    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $graph = $this->graph(prefix: 'rollback-');
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($graph['token'])->postJson('/api/v1/reports', $this->payload($graph))
            ->assertInternalServerError();
        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RPT-02] Authenticated throttling limits report draft creation.
    public function test_it_rate_limits_draft_creation(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->graph(prefix: 'limited-');
        $this->withToken($graph['token'])->postJson('/api/v1/reports', $this->payload($graph))->assertCreated();
        $this->withToken($graph['token'])->postJson('/api/v1/reports', $this->payload($graph))->assertTooManyRequests();
        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [RPT-02] The route, additive schema, and column-limited INSERT grant are versioned.
    public function test_it_registers_the_route_schema_and_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/reports'
            && in_array('POST', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:reports.create', $route->gatherMiddleware());
        $this->assertContains('throttle:auth.authenticated', $route->gatherMiddleware());
        $migration = file_get_contents(database_path('migrations/2026_08_25_000300_add_report_draft_content_fields.php'));
        $dcl = file_get_contents(database_path('sql/dcl/054_report_creation_grants.sql'));
        $this->assertIsString($migration);
        foreach (['audience', 'interpretation', 'limitations', 'recommendations', 'formats'] as $column) {
            $this->assertStringContainsString("'$column'", $migration);
        }
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT INSERT (', $dcl);
        $this->assertStringContainsString('ON TABLE app.reports TO mangroscan_api_rw;', $dcl);
        foreach (['GRANT UPDATE', 'GRANT DELETE', 'mangroscan_report_ro', 'mangroscan_worker', 'mangroscan_auditor'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    /** @return array<string, string> */
    private function graph(bool $permission = true, bool $foreignPermission = false, string $prefix = ''): array
    {
        $ids = [];
        foreach (['organization', 'foreign_organization', 'actor', 'foreign_actor', 'site', 'other_site', 'foreign_site', 'mission', 'foreign_mission'] as $key) {
            $ids[$key] = (string) Str::uuid();
        }
        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization'], 'organization_name' => $prefix.'Report Draft Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $ids['foreign_organization'], 'organization_name' => $prefix.'Foreign Report Draft Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($ids['actor'], $ids['organization'], $prefix.'report-author@example.test');
        $this->user($ids['foreign_actor'], $ids['foreign_organization'], $prefix.'foreign-report-author@example.test');
        $this->site($ids['site'], $ids['organization'], $ids['actor'], $prefix.'RPT-SITE');
        $this->site($ids['other_site'], $ids['organization'], $ids['actor'], $prefix.'RPT-SITE-2');
        $this->site($ids['foreign_site'], $ids['foreign_organization'], $ids['foreign_actor'], $prefix.'FOREIGN-RPT-SITE');
        $this->mission($ids['mission'], $ids['site'], $ids['actor'], $prefix.'RPT-MSN');
        $this->mission($ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor'], $prefix.'FOREIGN-RPT-MSN');

        $localRole = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRole, 'organization_id' => $ids['organization'], 'role_name' => $prefix.'Report Author', 'role_code' => $prefix.'report_author', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $ids['foreign_organization'], 'role_name' => $prefix.'Foreign Report Author', 'role_code' => $prefix.'foreign_report_author', 'created_at' => now(), 'updated_at' => now()],
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

        return $ids + ['token' => $actor->createToken($prefix.'report-store')->plainTextToken];
    }

    /** @param array<string, string> $graph
     * @return array<string, mixed>
     */
    private function payload(array $graph): array
    {
        return [
            'mission_id' => strtoupper($graph['mission']), 'site_id' => strtoupper($graph['site']),
            'report_title' => ' Quarterly Mangrove Review ', 'report_type' => ' VALIDATION_REPORT ',
            'audience' => ' Coastal managers ', 'summary' => ' Current monitoring evidence. ',
            'interpretation' => ' Canopy recovery remains stable. ', 'limitations' => ' Weather reduced coverage. ',
            'recommendations' => ' Repeat the eastern transect. ', 'formats' => [' PDF ', 'GeoJSON'],
        ];
    }

    private function user(string $id, string $organization, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organization, 'first_name' => 'Report', 'last_name' => 'Author', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $organization, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organization, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $site, string $actor, string $code): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $site, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Prepare report evidence.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }
}
