<?php

namespace Tests\Feature\Export;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExportedFileIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_exact_safe_tenant_export_metadata(): void
    {
        $graph = $this->graph();
        $response = $this->withToken($graph['token'])->getJson('/api/v1/exported-files');
        $response->assertOk()->assertJsonCount(2, 'data')->assertJsonCount(5, 'meta')
            ->assertJsonPath('data.0.export_file_id', $graph['latest_file'])
            ->assertJsonPath('data.0.export_type', 'geojson')
            ->assertJsonPath('meta.total', 2);
        $this->assertSame([
            'export_file_id', 'report_id', 'mission_id', 'export_type', 'file_name',
            'file_size_bytes', 'exported_by', 'exported_at',
        ], array_keys($response->json('data.0')));
        $this->assertArrayNotHasKey('file_path', $response->json('data.0'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_filters_by_scoped_report_mission_and_type(): void
    {
        $graph = $this->graph(prefix: 'filters-');
        $query = http_build_query(['report_id' => $graph['report'], 'mission_id' => $graph['mission'], 'type' => ' CSV ', 'page' => 1]);
        $this->withToken($graph['token'])->getJson('/api/v1/exported-files?'.$query)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.export_type', 'csv');
    }

    public function test_it_hides_filter_targets_and_inconsistent_lineage(): void
    {
        $graph = $this->graph(prefix: 'scope-');
        foreach ([
            ['report_id' => $graph['foreign_report']], ['mission_id' => $graph['foreign_mission']],
            ['report_id' => (string) Str::uuid()], ['mission_id' => (string) Str::uuid()],
            ['report_id' => $graph['report'], 'mission_id' => $graph['foreign_mission']],
        ] as $query) {
            $this->withToken($graph['token'])->getJson('/api/v1/exported-files?'.http_build_query($query))->assertNotFound();
        }
        DB::table('exported_files')->insert([
            'export_file_id' => (string) Str::uuid(), 'report_id' => $graph['report'],
            'mission_id' => $graph['foreign_mission'], 'export_type' => 'csv', 'file_name' => 'bad.csv',
            'file_path' => 'private/bad.csv', 'file_size_bytes' => 1, 'exported_by' => $graph['actor'], 'exported_at' => now(),
        ]);
        $this->withToken($graph['token'])->getJson('/api/v1/exported-files')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_it_validates_registry_filters(): void
    {
        $graph = $this->graph(prefix: 'validation-');
        $this->withToken($graph['token'])->getJson('/api/v1/exported-files?report_id=bad&mission_id=bad&type=pdf&page=0')
            ->assertUnprocessable()->assertJsonValidationErrors(['report_id', 'mission_id', 'type', 'page'], 'error.details');
    }

    public function test_it_enforces_access_and_active_identity(): void
    {
        $anonymous = $this->graph(prefix: 'anonymous-');
        $this->getJson('/api/v1/exported-files')->assertUnauthorized();
        $missing = $this->graph(prefix: 'missing-', permission: false);
        $this->app['auth']->forgetGuards();
        $this->withToken($missing['token'])->getJson('/api/v1/exported-files')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'exports.download');
        $inactive = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->withToken($inactive['token'])->getJson('/api/v1/exported-files')->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_it_rate_limits_registry_reads(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->graph(prefix: 'limited-');
        $this->withToken($graph['token'])->getJson('/api/v1/exported-files')->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/exported-files')->assertTooManyRequests();
    }

    public function test_it_versions_route_and_safe_column_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/exported-files' && in_array('GET', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:exports.download', $route->gatherMiddleware());
        $dcl = file_get_contents(database_path('sql/dcl/060_export_registry_grants.sql'));
        foreach (['export_file_id', 'file_name', 'file_size_bytes', 'exported_at', 'TO mangroscan_api_rw;'] as $fragment) {
            $this->assertStringContainsString($fragment, $dcl);
        }
        $this->assertStringNotContainsString('file_path', $dcl);
        $this->assertStringNotContainsString('GRANT UPDATE', $dcl);
        $this->assertStringNotContainsString('GRANT DELETE', $dcl);
    }

    /** @return array<string, string> */
    private function graph(string $prefix = '', bool $permission = true): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignActor = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => $prefix.'Registry Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Registry Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, $prefix.'registry@example.test');
        $this->user($foreignActor, $foreignOrg, $prefix.'foreign-registry@example.test');
        $local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $foreign = $this->lineage($foreignOrg, $foreignActor, $prefix.'FOREIGN');
        $report = (string) Str::uuid();
        $foreignReport = (string) Str::uuid();
        $this->report($report, $local['mission'], $local['site']);
        $this->report($foreignReport, $foreign['mission'], $foreign['site']);
        $this->file((string) Str::uuid(), $report, $local['mission'], 'csv', $actor, now()->subMinute());
        $latest = (string) Str::uuid();
        $this->file($latest, $report, $local['mission'], 'geojson', $actor, now());
        $this->file((string) Str::uuid(), $foreignReport, $foreign['mission'], 'csv', $foreignActor, now());
        $role = (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Downloader', 'role_code' => $prefix.'downloader', 'created_at' => now(), 'updated_at' => now()]);
        $permissionId = DB::table('permissions')->where('permission_code', 'exports.download')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'exports.download', 'permission_name' => 'Download exports', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['actor' => $actor, 'report' => $report, 'mission' => $local['mission'], 'foreign_report' => $foreignReport, 'foreign_mission' => $foreign['mission'], 'latest_file' => $latest, 'token' => User::query()->findOrFail($actor)->createToken($prefix.'registry')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Export', 'last_name' => 'Reader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{site:string,mission:string} */
    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Registry.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);

        return compact('site', 'mission');
    }

    private function report(string $id, string $mission, string $site): void
    {
        DB::table('reports')->insert(['report_id' => $id, 'mission_id' => $mission, 'site_id' => $site, 'report_title' => 'Registry Report', 'report_type' => 'monitoring_summary', 'report_status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function file(string $id, string $report, string $mission, string $type, string $actor, mixed $time): void
    {
        DB::table('exported_files')->insert(['export_file_id' => $id, 'report_id' => $report, 'mission_id' => $mission, 'export_type' => $type, 'file_name' => 'export.'.$type, 'file_path' => 'private/'.$id.'.'.$type, 'file_size_bytes' => 128, 'exported_by' => $actor, 'exported_at' => $time]);
    }
}
