<?php

namespace Tests\Feature\Site;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SitePlotStoreTest extends TestCase
{
    use RefreshDatabase;

    // [PLOT-02] A manager creates a normalized PostGIS plot and immutable audit evidence.
    public function test_it_creates_a_monitoring_plot(): void
    {
        $g = $this->createGraph();
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$g['token'], 'X-Request-ID' => 'req_plot_02', 'User-Agent' => 'Plot Test'])
            ->postJson('/api/v1/sites/'.$g['site_id'].'/plots', $this->payload());

        $response->assertCreated()->assertJsonPath('data.site_id', $g['site_id'])
            ->assertJsonPath('data.plot_code', 'PLOT-001')->assertJsonPath('data.plot_name', 'Validation Quadrat')
            ->assertJsonPath('data.plot_geom.type', 'Polygon')->assertJsonPath('data.area_square_meters', '2500.50')
            ->assertJsonPath('meta.request_id', 'req_plot_02');
        $plotId = $response->json('data.plot_id');
        $this->assertDatabaseHas('monitoring_plots', ['plot_id' => $plotId, 'site_id' => $g['site_id'], 'plot_code' => 'PLOT-001']);
        $audit = AuditLog::query()->sole();
        $this->assertSame('plot.create', $audit->action);
        $this->assertSame('monitoring_plots', $audit->table_name);
        $this->assertSame($plotId, $audit->record_id);
        $this->assertSame('Polygon', $audit->new_values['plot_geom']['type']);
    }

    // [PLOT-02] Required fields, bounds, positivity and polygon structure are validated.
    public function test_it_validates_plot_input(): void
    {
        $g = $this->createGraph();
        $this->withToken($g['token'])->postJson('/api/v1/sites/'.$g['site_id'].'/plots', [
            'plot_code' => ' ', 'plot_name' => str_repeat('x', 151), 'area_square_meters' => 0,
            'plot_geom' => ['type' => 'Polygon', 'coordinates' => [[[181, 10], [124, 10], [124, 11], [181, 10]]]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['plot_code', 'plot_name', 'area_square_meters', 'plot_geom'], 'error.details');
        $this->assertDatabaseCount('monitoring_plots', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [PLOT-02] Duplicate site-local codes are workflow conflicts, including soft-deleted codes.
    public function test_it_rejects_duplicate_plot_codes(): void
    {
        $g = $this->createGraph();
        $this->withToken($g['token'])->postJson('/api/v1/sites/'.$g['site_id'].'/plots', $this->payload())->assertCreated();
        $this->withToken($g['token'])->postJson('/api/v1/sites/'.$g['site_id'].'/plots', $this->payload())
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT')->assertJsonPath('error.details.plot_code', 'PLOT-001');
        $this->assertDatabaseCount('monitoring_plots', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [PLOT-02] Foreign, missing and malformed parent sites are hidden.
    public function test_it_hides_unavailable_sites(): void
    {
        $g = $this->createGraph();
        foreach ([$g['foreign_site_id'], (string) Str::uuid(), 'not-a-uuid'] as $site) {
            $this->withToken($g['token'])->postJson('/api/v1/sites/'.$site.'/plots', $this->payload())->assertNotFound();
        }
    }

    // [PLOT-02] Audit persistence failure rolls back the plot.
    public function test_it_rolls_back_when_audit_fails(): void
    {
        $g = $this->createGraph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($g['token'])->postJson('/api/v1/sites/'.$g['site_id'].'/plots', $this->payload())->assertInternalServerError();
        $this->assertDatabaseCount('monitoring_plots', 0);
    }

    // [PLOT-02] Authentication and tenant-valid plots.manage are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->postJson('/api/v1/sites/'.Str::uuid().'/plots', $this->payload())->assertUnauthorized();
        $g = $this->createGraph(localPermission: false);
        $this->withToken($g['token'])->postJson('/api/v1/sites/'.$g['site_id'].'/plots', $this->payload())->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'plots.manage');
    }

    // [PLOT-02] Foreign role assignments cannot authorize current-tenant creation.
    public function test_it_rejects_foreign_tenant_permission(): void
    {
        $g = $this->createGraph(localPermission: false, foreignPermission: true);
        $this->withToken($g['token'])->postJson('/api/v1/sites/'.$g['site_id'].'/plots', $this->payload())->assertForbidden();
    }

    // [PLOT-02] Creation shares the authenticated throttle budget.
    public function test_it_rate_limits_plot_creation(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->createGraph();
        $this->withToken($g['token'])->postJson('/api/v1/sites/'.$g['site_id'].'/plots', $this->payload())->assertCreated();
        $payload = $this->payload();
        $payload['plot_code'] = 'PLOT-002';
        $this->withToken($g['token'])->postJson('/api/v1/sites/'.$g['site_id'].'/plots', $payload)->assertTooManyRequests();
        $this->assertDatabaseCount('monitoring_plots', 1);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['plot_code' => ' plot-001 ', 'plot_name' => ' Validation Quadrat ', 'area_square_meters' => '2500.50', 'description' => ' Ground truth area ', 'plot_geom' => ['type' => 'Polygon', 'coordinates' => [[['123.8', '10.1'], [123.9, 10.1], [123.9, 10.2], ['123.8', '10.1']]]]];
    }

    /** @return array<string, string> */
    private function createGraph(bool $localPermission = true, bool $foreignPermission = false): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignUser = (string) Str::uuid();
        $role = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        $permission = (string) Str::uuid();
        $site = (string) Str::uuid();
        $foreignSite = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => 'Plot Create Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => 'Foreign Plot Create Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, 'plot-create@example.test');
        $this->user($foreignUser, $foreignOrg, 'foreign-plot-create@example.test');
        DB::table('roles')->insert([
            ['role_id' => $role, 'organization_id' => $org, 'role_name' => 'Plot Manager', 'role_code' => 'plot_manager', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $foreignOrg, 'role_name' => 'Foreign Plot Manager', 'role_code' => 'foreign_plot_manager', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert(['permission_id' => $permission, 'permission_code' => 'plots.manage', 'permission_name' => 'Manage plots', 'created_at' => now(), 'updated_at' => now()]);
        if ($localPermission || $foreignPermission) {
            DB::table('role_permissions')->insert(['role_id' => $foreignPermission ? $foreignRole : $role, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $foreignPermission ? $foreignRole : $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->site($site, $org, $actor, 'PLOT-CREATE');
        $this->site($foreignSite, $foreignOrg, $foreignUser, 'PLOT-FOREIGN-CREATE');

        return ['actor_id' => $actor, 'site_id' => $site, 'foreign_site_id' => $foreignSite, 'token' => User::findOrFail($actor)->createToken('plot-create')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Plot', 'last_name' => 'Manager', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $org, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }
}
