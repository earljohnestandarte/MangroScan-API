<?php

namespace Tests\Feature\Site;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SitePlotIndexTest extends TestCase
{
    use RefreshDatabase;

    // [PLOT-01] Plots are ordered, tenant scoped, and projected as GeoJSON.
    public function test_it_lists_site_plots_with_exact_fields_and_summary_count(): void
    {
        $g = $this->createGraph();
        $response = $this->withToken($g['token'])->withHeader('X-Request-ID', 'req_plot_01')
            ->getJson('/api/v1/sites/'.$g['site_id'].'/plots');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_plot_01')->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.plot_id', $g['alpha_plot_id'])
            ->assertJsonPath('data.0.site_id', $g['site_id'])
            ->assertJsonPath('data.0.plot_geom.type', 'Polygon')
            ->assertJsonPath('data.0.plot_geom.coordinates.0.0.0', 123.8)
            ->assertJsonPath('data.0.area_square_meters', '2500.50')
            ->assertJsonPath('data.1.plot_id', $g['beta_plot_id'])
            ->assertJsonPath('meta.request_id', 'req_plot_01');

        $this->assertSame(['plot_id', 'site_id', 'plot_code', 'plot_name', 'plot_geom', 'area_square_meters', 'description', 'created_at', 'updated_at'], array_keys($response->json('data.0')));
        $this->withToken($g['token'])->getJson('/api/v1/sites/'.$g['site_id'])->assertOk()->assertJsonPath('data.counts.plots', 2);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [PLOT-01] Deleted plots and foreign parent sites reveal no data.
    public function test_it_excludes_deleted_plots_and_hides_unavailable_sites(): void
    {
        $g = $this->createGraph();
        foreach ([$g['foreign_site_id'], (string) Str::uuid(), 'not-a-uuid'] as $site) {
            $this->withToken($g['token'])->getJson('/api/v1/sites/'.$site.'/plots')->assertNotFound();
        }
        $this->withToken($g['token'])->getJson('/api/v1/sites/'.$g['site_id'].'/plots')->assertJsonCount(2, 'data');
    }

    // [PLOT-01] Authentication and tenant-valid sites.read are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $g = $this->createGraph(localPermission: false);
        $this->getJson('/api/v1/sites/'.$g['site_id'].'/plots')->assertUnauthorized();
        $this->withToken($g['token'])->getJson('/api/v1/sites/'.$g['site_id'].'/plots')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'sites.read');
    }

    // [PLOT-01] Foreign-organization roles cannot authorize site reads.
    public function test_it_rejects_a_foreign_tenant_grant(): void
    {
        $g = $this->createGraph(localPermission: false, foreignPermission: true);
        $this->withToken($g['token'])->getJson('/api/v1/sites/'.$g['site_id'].'/plots')->assertForbidden();
    }

    // [PLOT-01] Plot reads share the authenticated throttle budget.
    public function test_it_rate_limits_plot_lists(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->createGraph();
        $uri = '/api/v1/sites/'.$g['site_id'].'/plots';
        $this->withToken($g['token'])->getJson($uri)->assertOk();
        $this->withToken($g['token'])->getJson($uri)->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [PLOT-01] PostgreSQL enforces positive area and least-privilege DCL.
    public function test_it_versions_plot_schema_guards_and_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_064200_create_monitoring_plots_table.php'));
        $dcl = file_get_contents(database_path('sql/dcl/014_monitoring_plot_grants.sql'));
        $this->assertIsString($migration);
        $this->assertStringContainsString("geometry('plot_geom', 'polygon', 4326)", $migration);
        $this->assertStringContainsString("->unique(['site_id', 'plot_code'])", $migration);
        $this->assertStringContainsString('monitoring_plots_area_check', $migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.monitoring_plots TO mangroscan_api_rw, mangroscan_report_ro;', $dcl);
        $this->assertStringNotContainsString('INSERT', $dcl);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $g = $this->createGraph('constraint-');
        $this->expectException(QueryException::class);
        DB::table('monitoring_plots')->where('plot_id', $g['alpha_plot_id'])->update(['area_square_meters' => 0]);
    }

    /** @return array<string, string> */
    private function createGraph(string $prefix = '', bool $localPermission = true, bool $foreignPermission = false): array
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
            ['organization_id' => $org, 'organization_name' => $prefix.'Plot Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Plot Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, $prefix.'plot@example.test');
        $this->user($foreignUser, $foreignOrg, $prefix.'foreign-plot@example.test');
        DB::table('roles')->insert([
            ['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Plot Reader', 'role_code' => $prefix.'plot_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $foreignOrg, 'role_name' => $prefix.'Foreign Plot Reader', 'role_code' => $prefix.'foreign_plot_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert(['permission_id' => $permission, 'permission_code' => 'sites.read', 'permission_name' => 'Read sites', 'created_at' => now(), 'updated_at' => now()]);
        if ($localPermission || $foreignPermission) {
            DB::table('role_permissions')->insert(['role_id' => $foreignPermission ? $foreignRole : $role, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $foreignPermission ? $foreignRole : $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->site($site, $org, $actor, $prefix.'PLOT-SITE');
        $this->site($foreignSite, $foreignOrg, $foreignUser, $prefix.'FOREIGN-PLOT-SITE');
        $alpha = (string) Str::uuid();
        $beta = (string) Str::uuid();
        $this->plot($beta, $site, $prefix.'PLOT-B', 'Beta Plot');
        $this->plot($alpha, $site, $prefix.'PLOT-A', 'Alpha Plot', false, true);
        $this->plot((string) Str::uuid(), $site, $prefix.'PLOT-DELETED', 'Deleted Plot', true);
        $this->plot((string) Str::uuid(), $foreignSite, $prefix.'PLOT-FOREIGN', 'Foreign Plot');

        return ['site_id' => $site, 'foreign_site_id' => $foreignSite, 'alpha_plot_id' => $alpha, 'beta_plot_id' => $beta, 'token' => User::findOrFail($actor)->createToken($prefix.'plot')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Plot', 'last_name' => 'Reader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $org, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function plot(string $id, string $site, string $code, string $name, bool $deleted = false, bool $details = false): void
    {
        $polygon = json_encode(['type' => 'Polygon', 'coordinates' => [[[123.8, 10.1], [123.9, 10.1], [123.9, 10.2], [123.8, 10.1]]]], JSON_THROW_ON_ERROR);
        $values = [$id, $site, $code, $name, $polygon, $details ? '2500.50' : null, $details ? 'Validation quadrat' : null, now(), now(), $deleted ? now() : null];
        if (DB::getDriverName() === 'pgsql') {
            DB::insert('INSERT INTO monitoring_plots (plot_id, site_id, plot_code, plot_name, plot_geom, area_square_meters, description, created_at, updated_at, deleted_at) VALUES (?, ?, ?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?, ?, ?, ?, ?)', $values);
        } else {
            DB::table('monitoring_plots')->insert(array_combine(['plot_id', 'site_id', 'plot_code', 'plot_name', 'plot_geom', 'area_square_meters', 'description', 'created_at', 'updated_at', 'deleted_at'], $values));
        }
    }
}
