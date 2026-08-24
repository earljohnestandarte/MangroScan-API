<?php

namespace Tests\Feature\Export;

use App\Contracts\Export\PrivateDownloadUrlIssuer;
use App\Exceptions\DownstreamServiceException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Export\FilesystemPrivateDownloadUrlIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ExportDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_issues_an_exact_audited_temporary_url(): void
    {
        Carbon::setTestNow('2026-08-25T10:00:00Z');
        config(['mangroscan.exports.disk' => 'local', 'mangroscan.exports.download_url_ttl_minutes' => 7]);
        $graph = $this->graph();
        $issuer = Mockery::mock(PrivateDownloadUrlIssuer::class);
        $issuer->shouldReceive('issue')->once()->with('local', $graph['path'], Mockery::on(
            fn ($expires): bool => Carbon::parse($expires)->equalTo(Carbon::parse('2026-08-25T10:07:00Z')),
        ))->andReturn('https://storage.test/private-export?signature=safe');
        $this->app->instance(PrivateDownloadUrlIssuer::class, $issuer);

        $response = $this->withToken($graph['token'])->withHeader('X-Request-ID', 'req_exp_03')
            ->postJson('/api/v1/exported-files/'.$graph['file'].'/download');
        $response->assertOk()->assertHeader('X-Request-ID', 'req_exp_03')->assertExactJson(['data' => [
            'url' => 'https://storage.test/private-export?signature=safe',
            'expires_at' => '2026-08-25T10:07:00+00:00',
        ]]);
        $audit = AuditLog::query()->sole();
        $this->assertSame('export.download.issue', $audit->action);
        $this->assertSame($graph['file'], $audit->record_id);
        $this->assertSame('2026-08-25T10:07:00+00:00', $audit->new_values['expires_at']);
        $this->assertArrayNotHasKey('url', $audit->new_values);
        $this->assertArrayNotHasKey('file_path', $audit->new_values);
    }

    public function test_it_hides_unavailable_or_inconsistent_exports(): void
    {
        $graph = $this->graph(prefix: 'scope-');
        $issuer = Mockery::mock(PrivateDownloadUrlIssuer::class);
        $issuer->shouldNotReceive('issue');
        $this->app->instance(PrivateDownloadUrlIssuer::class, $issuer);
        foreach (['bad', (string) Str::uuid(), $graph['foreign_file'], $graph['inconsistent_file']] as $id) {
            $this->withToken($graph['token'])->postJson('/api/v1/exported-files/'.$id.'/download')->assertNotFound();
        }
        DB::table('survey_missions')->where('mission_id', $graph['mission'])->update(['deleted_at' => now()]);
        $this->withToken($graph['token'])->postJson('/api/v1/exported-files/'.$graph['file'].'/download')->assertNotFound();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_enforces_access_and_active_identity(): void
    {
        $anonymous = $this->graph(prefix: 'anonymous-');
        $this->postJson('/api/v1/exported-files/'.$anonymous['file'].'/download')->assertUnauthorized();
        $missing = $this->graph(prefix: 'missing-', permission: false);
        $this->app['auth']->forgetGuards();
        $this->withToken($missing['token'])->postJson('/api/v1/exported-files/'.$missing['file'].'/download')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'exports.download');
        $inactive = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->withToken($inactive['token'])->postJson('/api/v1/exported-files/'.$inactive['file'].'/download')
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_url_issuance_failure_rolls_back_audit(): void
    {
        $graph = $this->graph(prefix: 'issuer-failure-');
        $issuer = Mockery::mock(PrivateDownloadUrlIssuer::class);
        $issuer->shouldReceive('issue')->once()->andThrow(new DownstreamServiceException('Unavailable', 503, 'SERVICE_UNAVAILABLE'));
        $this->app->instance(PrivateDownloadUrlIssuer::class, $issuer);
        $this->withToken($graph['token'])->postJson('/api/v1/exported-files/'.$graph['file'].'/download')
            ->assertServiceUnavailable()->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_prevents_url_issuance(): void
    {
        $graph = $this->graph(prefix: 'audit-failure-');
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $issuer = Mockery::mock(PrivateDownloadUrlIssuer::class);
        $issuer->shouldNotReceive('issue');
        $this->app->instance(AuditLogger::class, $audit);
        $this->app->instance(PrivateDownloadUrlIssuer::class, $issuer);
        $this->withToken($graph['token'])->postJson('/api/v1/exported-files/'.$graph['file'].'/download')->assertInternalServerError();
    }

    public function test_it_rate_limits_download_issuance(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->graph(prefix: 'limited-');
        $issuer = Mockery::mock(PrivateDownloadUrlIssuer::class);
        $issuer->shouldReceive('issue')->once()->andReturn('https://storage.test/once');
        $this->app->instance(PrivateDownloadUrlIssuer::class, $issuer);
        $url = '/api/v1/exported-files/'.$graph['file'].'/download';
        $this->withToken($graph['token'])->postJson($url)->assertOk();
        $this->withToken($graph['token'])->postJson($url)->assertTooManyRequests();
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_filesystem_issuer_maps_missing_objects_to_unavailable(): void
    {
        Storage::fake('local');
        $this->expectException(DownstreamServiceException::class);
        $this->expectExceptionMessage('The export artifact is unavailable.');
        (new FilesystemPrivateDownloadUrlIssuer)->issue('local', 'exports/missing.csv', now()->addMinutes(10));
    }

    public function test_it_versions_route_binding_config_and_narrow_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/exported-files/{exportedFile}/download' && in_array('POST', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:exports.download', $route->gatherMiddleware());
        $this->assertInstanceOf(PrivateDownloadUrlIssuer::class, app(PrivateDownloadUrlIssuer::class));
        $this->assertSame(10, config('mangroscan.exports.download_url_ttl_minutes'));
        $dcl = file_get_contents(database_path('sql/dcl/061_export_download_grants.sql'));
        $this->assertStringContainsString('SELECT (file_path)', $dcl);
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
        DB::table('organizations')->insert([['organization_id' => $org, 'organization_name' => $prefix.'Download Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Download Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($actor, $org, $prefix.'download@example.test');
        $this->user($foreignActor, $foreignOrg, $prefix.'foreign-download@example.test');
        $local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $foreign = $this->lineage($foreignOrg, $foreignActor, $prefix.'FOREIGN');
        $report = (string) Str::uuid();
        $foreignReport = (string) Str::uuid();
        $this->report($report, $local['mission'], $local['site']);
        $this->report($foreignReport, $foreign['mission'], $foreign['site']);
        $file = (string) Str::uuid();
        $foreignFile = (string) Str::uuid();
        $inconsistent = (string) Str::uuid();
        $path = 'exports/'.$org.'/'.$report.'/result.csv';
        $this->file($file, $report, $local['mission'], $actor, $path);
        $this->file($foreignFile, $foreignReport, $foreign['mission'], $foreignActor, 'exports/'.$foreignFile.'.csv');
        $this->file($inconsistent, $report, $foreign['mission'], $actor, 'exports/'.$inconsistent.'.csv');
        $role = (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Downloader', 'role_code' => $prefix.'downloader', 'created_at' => now(), 'updated_at' => now()]);
        $permissionId = DB::table('permissions')->where('permission_code', 'exports.download')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'exports.download', 'permission_name' => 'Download exports', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['actor' => $actor, 'mission' => $local['mission'], 'file' => $file, 'foreign_file' => $foreignFile, 'inconsistent_file' => $inconsistent, 'path' => $path, 'token' => User::query()->findOrFail($actor)->createToken($prefix.'download')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Export', 'last_name' => 'Downloader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{site:string,mission:string} */
    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Download export.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);

        return compact('site', 'mission');
    }

    private function report(string $id, string $mission, string $site): void
    {
        DB::table('reports')->insert(['report_id' => $id, 'mission_id' => $mission, 'site_id' => $site, 'report_title' => 'Download Report', 'report_type' => 'monitoring_summary', 'report_status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function file(string $id, string $report, string $mission, string $actor, string $path): void
    {
        DB::table('exported_files')->insert(['export_file_id' => $id, 'report_id' => $report, 'mission_id' => $mission, 'export_type' => 'csv', 'file_name' => 'result.csv', 'file_path' => $path, 'file_size_bytes' => 128, 'exported_by' => $actor, 'exported_at' => now()]);
    }
}
