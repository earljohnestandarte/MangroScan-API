<?php

namespace Tests\Feature\Export;

use App\Jobs\GenerateExportArtifact;
use App\Models\ExportJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Export\ExportExecutionService;
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

class ExportStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['mangroscan.media.disk' => 'local']);
    }

    public function test_it_queues_an_exact_export_job(): void
    {
        Queue::fake();
        $graph = $this->graph();
        $response = $this->request($graph, 'export-1', ['format' => ' GEOJSON ', 'filters' => ['validation_status' => ' VALIDATED ']], 'req_exp_01');
        $response->assertAccepted()->assertHeader('X-Request-ID', 'req_exp_01')->assertExactJson(['data' => [
            'job_id' => $response->json('data.job_id'), 'export_type' => 'geojson',
        ]]);
        $job = ExportJob::query()->findOrFail($response->json('data.job_id'));
        $this->assertSame(['validation_status' => 'validated'], $job->filters);
        $this->assertDatabaseHas('audit_logs', ['action' => 'export.generate.queue', 'record_id' => $job->export_job_id]);
        Queue::assertPushed(GenerateExportArtifact::class, 1);
    }

    public function test_it_is_idempotent_and_prevents_overlapping_types(): void
    {
        Queue::fake();
        $graph = $this->graph(prefix: 'idempotent-');
        $first = $this->request($graph, 'same', ['format' => 'csv'])->assertAccepted();
        $this->request($graph, 'same', ['format' => 'csv'])->assertAccepted()->assertJsonPath('data.job_id', $first->json('data.job_id'));
        $this->request($graph, 'same', ['format' => 'kml'])->assertConflict();
        $this->request($graph, 'other', ['format' => 'csv'])->assertConflict()->assertJsonPath('error.details.export_type', 'csv');
        $this->request($graph, 'xlsx', ['format' => 'xlsx'])->assertAccepted();
        $this->assertDatabaseCount('export_jobs', 2);
    }

    public function test_the_worker_generates_all_supported_private_formats(): void
    {
        Queue::fake();
        $graph = $this->graph(prefix: 'worker-');
        foreach (['csv', 'xlsx', 'geojson', 'kml'] as $format) {
            $response = $this->request($graph, 'worker-'.$format, ['format' => $format]);
            $jobId = $response->json('data.job_id');
            (new GenerateExportArtifact($jobId))->handle(app(ExportExecutionService::class));
            $job = ExportJob::query()->findOrFail($jobId);
            $this->assertSame('completed', $job->job_status);
            $file = DB::table('exported_files')->where('export_file_id', $job->exported_file_id)->first();
            $this->assertNotNull($file);
            Storage::disk('local')->assertExists($file->file_path);
            $bytes = Storage::disk('local')->get($file->file_path);
            $this->assertSame(strlen($bytes), (int) $file->file_size_bytes);
            match ($format) {
                'csv' => $this->assertStringContainsString('tree_observation_id,tree_code', $bytes),
                'xlsx' => $this->assertStringStartsWith("PK\x03\x04", $bytes),
                'geojson' => $this->assertSame('FeatureCollection', json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['type']),
                'kml' => $this->assertStringContainsString('<kml xmlns="http://www.opengis.net/kml/2.2">', $bytes),
            };
        }
        $this->assertDatabaseCount('exported_files', 4);
        $this->assertSame(4, DB::table('audit_logs')->where('action', 'export.generate.complete')->count());
    }

    public function test_worker_applies_canonical_filters(): void
    {
        Queue::fake();
        $graph = $this->graph(prefix: 'filters-');
        $response = $this->request($graph, 'filtered', ['format' => 'geojson', 'filters' => ['species_id' => $graph['species'], 'validation_status' => 'validated']]);
        app(ExportExecutionService::class)->execute($response->json('data.job_id'));
        $path = DB::table('exported_files')->value('file_path');
        $json = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $json['features']);
        $this->assertSame('TREE-001', $json['features'][0]['properties']['tree_code']);
        $this->assertSame([123.301, 9.3065], $json['features'][0]['geometry']['coordinates']);
    }

    public function test_it_validates_headers_formats_filters_and_reserved_options(): void
    {
        Queue::fake();
        $graph = $this->graph(prefix: 'validation-');
        $this->request($graph, '', ['format' => 'csv'])->assertBadRequest();
        $this->request($graph, str_repeat('k', 101), ['format' => 'csv'])->assertBadRequest();
        $this->request($graph, 'invalid', ['format' => 'pdf', 'filters' => ['species_id' => 'bad', 'validation_status' => 'maybe', 'unknown' => true], 'options' => ['unknown' => true]])
            ->assertUnprocessable()->assertJsonValidationErrors(['format', 'filters', 'filters.species_id', 'filters.validation_status', 'options'], 'error.details');
    }

    public function test_it_hides_unavailable_report_lineage(): void
    {
        Queue::fake();
        $graph = $this->graph(prefix: 'scope-');
        foreach (['bad', (string) Str::uuid(), $graph['foreign_report'], $graph['inconsistent_report']] as $id) {
            $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'scope-'.$id)
                ->postJson('/api/v1/reports/'.$id.'/exports', ['format' => 'csv'])->assertNotFound();
        }
        DB::table('survey_missions')->where('mission_id', $graph['mission'])->update(['deleted_at' => now()]);
        $this->request($graph, 'deleted', ['format' => 'csv'])->assertNotFound();
    }

    public function test_it_requires_both_tenant_permissions_and_active_identity(): void
    {
        Queue::fake();
        $anonymous = $this->graph(prefix: 'anonymous-');
        $this->withHeader('Idempotency-Key', 'anonymous')->postJson('/api/v1/reports/'.$anonymous['report'].'/exports', ['format' => 'csv'])->assertUnauthorized();
        foreach ([['results.export'], ['reports.generate']] as $missing) {
            $graph = $this->graph(prefix: $missing[0].'-', missingPermission: $missing[0]);
            $this->app['auth']->forgetGuards();
            $this->request($graph, 'missing', ['format' => 'csv'])->assertForbidden()->assertJsonPath('error.details.required_permission', $missing[0]);
        }
        $inactive = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->request($inactive, 'inactive', ['format' => 'csv'])->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_it_rolls_back_when_queue_audit_fails(): void
    {
        Queue::fake();
        $graph = $this->graph(prefix: 'rollback-');
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->request($graph, 'rollback', ['format' => 'csv'])->assertInternalServerError();
        $this->assertDatabaseCount('export_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_rate_limits_export_creation(): void
    {
        Queue::fake();
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->graph(prefix: 'limited-');
        $this->request($graph, 'first', ['format' => 'csv'])->assertAccepted();
        $this->request($graph, 'second', ['format' => 'xlsx'])->assertTooManyRequests();
    }

    public function test_it_versions_route_schema_worker_and_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/reports/{report}/exports' && in_array('POST', $route->methods(), true));
        $this->assertNotNull($route);
        foreach (['auth:sanctum', 'permission:results.export', 'permission:reports.generate'] as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware());
        }
        $migration = file_get_contents(database_path('migrations/2026_08_25_000600_create_export_tables.php'));
        foreach (['exported_files_type_check', 'export_jobs_status_check', 'export_jobs_completion_check', 'export_jobs_one_active_type_per_report', 'trg_export_jobs_touch_updated_at'] as $fragment) {
            $this->assertStringContainsString($fragment, $migration);
        }
        $dcl = file_get_contents(database_path('sql/dcl/059_export_generation_grants.sql'));
        $this->assertStringContainsString('TO mangroscan_worker;', $dcl);
        $this->assertStringNotContainsString('GRANT DELETE', $dcl);
        $this->assertStringNotContainsString('file_path) ON TABLE app.exported_files TO mangroscan_api_rw', $dcl);
    }

    /** @param array<string, string> $graph
     * @param array<string, mixed> $payload */
    private function request(array $graph, string $key, array $payload, ?string $requestId = null): TestResponse
    {
        $request = $this->withToken($graph['token'])->withHeader('Idempotency-Key', $key);
        if ($requestId !== null) {
            $request->withHeader('X-Request-ID', $requestId);
        }

        return $request->postJson('/api/v1/reports/'.$graph['report'].'/exports', $payload);
    }

    /** @return array<string, string> */
    private function graph(string $prefix = '', ?string $missingPermission = null): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignActor = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => $prefix.'Export Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Export Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, $prefix.'export@example.test');
        $this->user($foreignActor, $foreignOrg, $prefix.'foreign-export@example.test');
        $local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $foreign = $this->lineage($foreignOrg, $foreignActor, $prefix.'FOREIGN');
        $report = (string) Str::uuid();
        $foreignReport = (string) Str::uuid();
        $inconsistent = (string) Str::uuid();
        $this->report($report, $local['mission'], $local['site'], $actor);
        $this->report($foreignReport, $foreign['mission'], $foreign['site'], $foreignActor);
        $this->report($inconsistent, $foreign['mission'], $local['site'], $actor);
        $species = $this->species($prefix.'Rhizophora stylosa');
        $other = $this->species($prefix.'Sonneratia alba');
        $this->tree((string) Str::uuid(), $local['mission'], $local['flight'], 'TREE-001', $species, 'validated', 123.301);
        $this->tree((string) Str::uuid(), $local['mission'], $local['flight'], 'TREE-002', $species, 'unvalidated', 123.302);
        $this->tree((string) Str::uuid(), $local['mission'], $local['flight'], 'TREE-003', $other, 'corrected', 123.303);
        $role = (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Exporter', 'role_code' => $prefix.'exporter', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['results.export', 'reports.generate'] as $code) {
            $permission = DB::table('permissions')->where('permission_code', $code)->value('permission_id') ?? (string) Str::uuid();
            DB::table('permissions')->insertOrIgnore(['permission_id' => $permission, 'permission_code' => $code, 'permission_name' => $code, 'created_at' => now(), 'updated_at' => now()]);
            if ($missingPermission !== $code) {
                DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);

        return ['actor' => $actor, 'mission' => $local['mission'], 'report' => $report, 'foreign_report' => $foreignReport, 'inconsistent_report' => $inconsistent, 'species' => $species, 'token' => User::query()->findOrFail($actor)->createToken($prefix.'export')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Export', 'last_name' => 'User', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{site:string,mission:string,flight:string} */
    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Export trees.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => $code, 'model' => 'Test', 'serial_number' => $code.'-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);

        return compact('site', 'mission', 'flight');
    }

    private function report(string $id, string $mission, string $site, string $actor): void
    {
        DB::table('reports')->insert(['report_id' => $id, 'mission_id' => $mission, 'site_id' => $site, 'report_title' => 'Export Report', 'report_type' => 'monitoring_summary', 'report_status' => 'draft', 'formats' => json_encode(['csv', 'xlsx', 'geojson', 'kml']), 'generated_by' => null, 'approved_by' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function species(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('mangrove_species')->insert(['species_id' => $id, 'scientific_name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function tree(string $id, string $mission, string $flight, string $code, string $species, string $status, float $longitude): void
    {
        $point = json_encode(['type' => 'Point', 'coordinates' => [$longitude, 9.3065]], JSON_THROW_ON_ERROR);
        DB::table('tree_observations')->insert(['tree_observation_id' => $id, 'mission_id' => $mission, 'flight_session_id' => $flight, 'tree_code' => $code, 'tree_location' => DB::getDriverName() === 'pgsql' ? DB::raw("ST_SetSRID(ST_GeomFromGeoJSON('$point'),4326)") : $point, 'detection_confidence' => 0.94, 'final_species_id' => $species, 'final_height_meters' => 4.8, 'final_estimated_age_years' => 6, 'validation_status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }
}
