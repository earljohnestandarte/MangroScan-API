<?php

namespace Tests\Feature\Processing;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessingJobIndexTest extends TestCase
{
    use RefreshDatabase;

    // [JOB-01] Tenant jobs use the exact resource and pagination envelope.
    public function test_it_lists_tenant_processing_jobs_with_exact_safe_fields(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])->withHeader('X-Request-ID', 'req_job_01')
            ->getJson('/api/v1/processing-jobs?per_page=2&page=1');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_job_01')
            ->assertJsonPath('meta', ['request_id' => 'req_job_01', 'page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.processing_job_id', $graph['latest_job_id'])
            ->assertJsonPath('data.0.job_type', 'full_pipeline')->assertJsonPath('data.0.job_status', 'running')
            ->assertJsonPath('data.0.parameters.confidence', 0.25)->assertJsonPath('data.0.progress_percent', 40);

        $this->assertSame([
            'processing_job_id', 'mission_id', 'flight_session_id', 'requested_by_user_id',
            'job_type', 'job_status', 'parameters', 'progress_percent', 'attempt_count',
            'queued_at', 'started_at', 'completed_at', 'error_code', 'error_message',
            'output_summary', 'created_at', 'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [JOB-01] All documented filters compose after normalization.
    public function test_it_filters_processing_jobs(): void
    {
        $g = $this->createGraph();
        $this->withToken($g['token'])->getJson('/api/v1/processing-jobs?mission_id='.$g['mission_id'].'&flight_id='.$g['flight_id'].'&status= FAILED &type=TREE_DETECTION')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.processing_job_id', $g['failed_job_id']);
    }

    // [JOB-01] Invalid filter values fail before scoped lookups.
    public function test_it_validates_processing_job_filters(): void
    {
        $g = $this->createGraph();
        $this->withToken($g['token'])->getJson('/api/v1/processing-jobs?mission_id=nope&flight_id=nope&status=done&type=combined&page=0&per_page=101')
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['mission_id', 'flight_id', 'status', 'type', 'page', 'per_page'], 'error.details');
    }

    // [JOB-01] Foreign and unavailable filter resources are non-enumerable.
    public function test_it_hides_foreign_and_missing_filter_resources(): void
    {
        $g = $this->createGraph();
        foreach (['mission_id='.$g['foreign_mission_id'], 'flight_id='.$g['foreign_flight_id'], 'mission_id='.Str::uuid()] as $query) {
            $this->withToken($g['token'])->getJson('/api/v1/processing-jobs?'.$query)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    // [JOB-01] Authentication and current-tenant processing management are required.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $g = $this->createGraph(permission: false);
        $this->getJson('/api/v1/processing-jobs')->assertUnauthorized();
        $this->withToken($g['token'])->getJson('/api/v1/processing-jobs')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'processing_jobs.manage');
    }

    // [JOB-01] Listings share the authenticated request budget.
    public function test_it_rate_limits_processing_job_lists(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->createGraph();
        $this->withToken($g['token'])->getJson('/api/v1/processing-jobs')->assertOk();
        $this->withToken($g['token'])->getJson('/api/v1/processing-jobs')->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [JOB-01] PostgreSQL enforces job domains and DCL remains read-only.
    public function test_it_versions_processing_job_guards_and_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_064100_create_processing_jobs_table.php'));
        $dcl = file_get_contents(database_path('sql/dcl/013_processing_job_grants.sql'));
        $this->assertIsString($migration);
        foreach (['processing_jobs_type_check', 'processing_jobs_status_check', 'processing_jobs_progress_check', 'processing_jobs_timestamps_check'] as $guard) {
            $this->assertStringContainsString($guard, $migration);
        }
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.processing_jobs TO mangroscan_api_rw, mangroscan_report_ro;', $dcl);
        $this->assertStringNotContainsString('INSERT', $dcl);
        $this->assertStringNotContainsString('mangroscan_worker', $dcl);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $g = $this->createGraph('constraint-');
        $this->expectException(QueryException::class);
        DB::table('processing_jobs')->where('processing_job_id', $g['latest_job_id'])->update(['progress_percent' => 101]);
    }

    /** @return array<string, string> */
    private function createGraph(string $prefix = '', bool $permission = true): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignUser = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => $prefix.'Jobs Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Jobs Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, $prefix.'jobs@example.test');
        $this->user($foreignUser, $foreignOrg, $prefix.'foreign-jobs@example.test');
        $role = (string) Str::uuid();
        $permissionId = (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Job Manager', 'role_code' => $prefix.'job_manager', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insert(['permission_id' => $permissionId, 'permission_code' => 'processing_jobs.manage', 'permission_name' => 'Manage processing jobs', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $site = (string) Str::uuid();
        $foreignSite = (string) Str::uuid();
        $this->site($site, $org, $actor, $prefix.'JOB-SITE');
        $this->site($foreignSite, $foreignOrg, $foreignUser, $prefix.'FOREIGN-JOB-SITE');
        $mission = (string) Str::uuid();
        $foreignMission = (string) Str::uuid();
        $this->mission($mission, $site, $actor, $prefix.'JOB-MISSION');
        $this->mission($foreignMission, $foreignSite, $foreignUser, $prefix.'FOREIGN-JOB-MISSION');
        $drone = (string) Str::uuid();
        $foreignDrone = (string) Str::uuid();
        $this->drone($drone, $org, $prefix.'JOB-DRONE');
        $this->drone($foreignDrone, $foreignOrg, $prefix.'FOREIGN-JOB-DRONE');
        $flight = (string) Str::uuid();
        $foreignFlight = (string) Str::uuid();
        $this->flight($flight, $mission, $drone, $actor, $prefix.'JOB-FLIGHT');
        $this->flight($foreignFlight, $foreignMission, $foreignDrone, $foreignUser, $prefix.'FOREIGN-JOB-FLIGHT');
        $latest = (string) Str::uuid();
        $failed = (string) Str::uuid();
        $this->job((string) Str::uuid(), $mission, null, $actor, 'species_classification', 'succeeded', '2026-08-12T01:00:00+00:00');
        $this->job($failed, $mission, $flight, $actor, 'tree_detection', 'failed', '2026-08-12T02:00:00+00:00');
        $this->job($latest, $mission, $flight, $actor, 'full_pipeline', 'running', '2026-08-12T03:00:00+00:00', true);
        $this->job((string) Str::uuid(), $foreignMission, $foreignFlight, $foreignUser, 'full_pipeline', 'queued', '2026-08-12T04:00:00+00:00');

        return ['mission_id' => $mission, 'foreign_mission_id' => $foreignMission, 'flight_id' => $flight, 'foreign_flight_id' => $foreignFlight, 'latest_job_id' => $latest, 'failed_job_id' => $failed, 'token' => User::findOrFail($actor)->createToken($prefix.'job')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Job', 'last_name' => 'Manager', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $org, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $site, string $actor, string $code): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $site, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Process imagery.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function drone(string $id, string $org, string $serial): void
    {
        DB::table('drones')->insert(['drone_id' => $id, 'organization_id' => $org, 'drone_name' => $serial, 'model' => 'Test', 'serial_number' => $serial, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function flight(string $id, string $mission, string $drone, string $pilot, string $code): void
    {
        DB::table('flight_sessions')->insert(['flight_session_id' => $id, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $pilot, 'flight_code' => $code, 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function job(string $id, string $mission, ?string $flight, string $actor, string $type, string $status, string $queuedAt, bool $details = false): void
    {
        DB::table('processing_jobs')->insert(['processing_job_id' => $id, 'mission_id' => $mission, 'flight_session_id' => $flight, 'requested_by_user_id' => $actor, 'job_type' => $type, 'job_status' => $status, 'parameters' => $details ? json_encode(['confidence' => 0.25], JSON_THROW_ON_ERROR) : null, 'progress_percent' => $details ? 40 : ($status === 'succeeded' ? 100 : 0), 'attempt_count' => 1, 'queued_at' => $queuedAt, 'started_at' => $status === 'queued' ? null : $queuedAt, 'completed_at' => in_array($status, ['succeeded', 'failed'], true) ? $queuedAt : null, 'error_code' => $status === 'failed' ? 'INFERENCE_FAILED' : null, 'error_message' => $status === 'failed' ? 'Model failed.' : null, 'output_summary' => $status === 'succeeded' ? json_encode(['detections' => 4], JSON_THROW_ON_ERROR) : null, 'created_at' => $queuedAt, 'updated_at' => $queuedAt]);
    }
}
