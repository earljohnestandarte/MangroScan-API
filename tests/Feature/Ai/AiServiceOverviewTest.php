<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiServiceOverviewTest extends TestCase
{
    use RefreshDatabase;

    // [AISVC-01] Administrators receive safe services and scoped overview aggregates.
    public function test_it_returns_the_safe_ai_backend_overview(): void
    {
        Http::fake();
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_aisvc_01')
            ->getJson('/api/v1/admin/ai-services');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_aisvc_01')
            ->assertJsonPath('meta.request_id', 'req_aisvc_01')
            ->assertJsonCount(2, 'data.services')
            ->assertJsonPath('data.services.0.ai_service_id', $graph['enabled_service_id'])
            ->assertJsonPath('data.services.0.health_status', 'healthy')
            ->assertJsonPath('data.services.0.capabilities.detector', true)
            ->assertJsonPath('data.services.0.last_health_checked_at', '2026-08-12T04:00:00+00:00')
            ->assertJsonPath('data.services.1.ai_service_id', $graph['disabled_service_id'])
            ->assertJsonPath('data.models', ['total' => 2, 'deployed' => 1, 'versions' => 2])
            ->assertJsonPath('data.jobs', [
                'total' => 3, 'queued' => 1, 'running' => 0, 'completed' => 1, 'failed' => 1,
            ]);

        $this->assertSame([
            'ai_service_id', 'service_name', 'base_url', 'environment', 'enabled',
            'health_status', 'service_version', 'capabilities', 'last_health_checked_at',
            'last_synchronized_at', 'created_by', 'created_at', 'updated_at',
        ], array_keys($response->json('data.services.0')));
        foreach ($response->json('data.services') as $service) {
            $this->assertArrayNotHasKey('api_key', $service);
            $this->assertArrayNotHasKey('encrypted_api_key', $service);
        }
        $this->assertStringNotContainsString('ciphertext-secret', $response->getContent());
        $this->assertDatabaseCount('audit_logs', 0);
        Http::assertNothingSent();
    }

    // [AISVC-01] An empty registry and workload retain the exact overview shape.
    public function test_it_returns_empty_overview_values(): void
    {
        $graph = $this->createIdentity();

        $this->withToken($graph['token'])->getJson('/api/v1/admin/ai-services')
            ->assertOk()
            ->assertJsonPath('data.services', [])
            ->assertJsonPath('data.models', ['total' => 0, 'deployed' => 0, 'versions' => 0])
            ->assertJsonPath('data.jobs', [
                'total' => 0, 'queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0,
            ]);
    }

    // [AISVC-01] Authentication and a tenant-valid administrator grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $this->getJson('/api/v1/admin/ai-services')->assertUnauthorized();

        $missing = $this->createIdentity(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->getJson('/api/v1/admin/ai-services')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'ai_services.manage');

        $foreign = $this->createIdentity(foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->getJson('/api/v1/admin/ai-services')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'ai_services.manage');
    }

    // [AISVC-01] Inactive administrators cannot inspect service configuration.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->createIdentity(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($graph['token'])->getJson('/api/v1/admin/ai-services')
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [AISVC-01] Overview reads use the shared authenticated request budget.
    public function test_it_rate_limits_ai_service_overviews(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createIdentity();

        $this->withToken($graph['token'])->getJson('/api/v1/admin/ai-services')->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/admin/ai-services')
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [AISVC-01] The schema and DCL preserve the encrypted credential boundary.
    public function test_it_versions_the_service_schema_and_column_level_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_064700_create_ai_services_table.php'));
        $dcl = file_get_contents(database_path('sql/dcl/027_ai_service_overview_grants.sql'));

        $this->assertIsString($migration);
        foreach (['ai_services', 'encrypted_api_key', 'capabilities', 'ai_services_health_status_check'] as $object) {
            $this->assertStringContainsString($object, $migration);
        }
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT (', $dcl);
        $this->assertStringContainsString('ON TABLE app.ai_services TO mangroscan_api_rw;', $dcl);
        foreach (['encrypted_api_key', 'mangroscan_report_ro', 'mangroscan_worker', 'INSERT', 'UPDATE', 'DELETE'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    // [AISVC-01] PostgreSQL rejects undocumented service-health states.
    public function test_postgresql_enforces_service_health_states(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL AI-service verification.');
        }

        $graph = $this->createGraph(prefix: 'constraint-');
        $this->expectException(QueryException::class);
        DB::table('ai_services')->where('ai_service_id', $graph['enabled_service_id'])
            ->update(['health_status' => 'compromised']);
    }

    /** @return array<string, string> */
    private function createGraph(string $prefix = ''): array
    {
        $identity = $this->createIdentity(prefix: $prefix);
        $now = now();
        $enabledServiceId = (string) Str::uuid();
        $disabledServiceId = (string) Str::uuid();
        DB::table('ai_services')->insert([
            [
                'ai_service_id' => $enabledServiceId, 'service_name' => $prefix.'Primary Inference',
                'base_url' => 'https://'.$prefix.'primary-ai.example.test',
                'encrypted_api_key' => 'ciphertext-secret-primary', 'environment' => 'production',
                'enabled' => true, 'health_status' => 'healthy', 'service_version' => '2.4.0',
                'capabilities' => json_encode(['detector' => true], JSON_THROW_ON_ERROR),
                'last_health_checked_at' => '2026-08-12T04:00:00+00:00',
                'last_synchronized_at' => '2026-08-12T03:00:00+00:00',
                'created_by' => $identity['actor_id'], 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'ai_service_id' => $disabledServiceId, 'service_name' => $prefix.'Archive Inference',
                'base_url' => 'https://'.$prefix.'archive-ai.example.test',
                'encrypted_api_key' => 'ciphertext-secret-archive', 'environment' => 'staging',
                'enabled' => false, 'health_status' => 'unknown', 'service_version' => null,
                'capabilities' => null, 'last_health_checked_at' => null,
                'last_synchronized_at' => null, 'created_by' => $identity['actor_id'],
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        $activeModelId = $this->model($identity['actor_id'], $prefix.'Detector', false);
        $this->model($identity['foreign_user_id'], $prefix.'Classifier', false);
        $deletedModelId = $this->model($identity['actor_id'], $prefix.'Deleted', true);
        $this->version($activeModelId, 'v1', false);
        $this->version($activeModelId, 'v2', true);
        $this->version($deletedModelId, 'v0', true);

        $localSite = $this->site($identity['organization_id'], $identity['actor_id'], $prefix.'LOCAL');
        $foreignSite = $this->site($identity['foreign_organization_id'], $identity['foreign_user_id'], $prefix.'FOREIGN');
        $localMission = $this->mission($localSite, $identity['actor_id'], $prefix.'LOCAL');
        $foreignMission = $this->mission($foreignSite, $identity['foreign_user_id'], $prefix.'FOREIGN');
        foreach (['queued', 'completed', 'failed'] as $status) {
            $this->job($localMission, $identity['actor_id'], $status);
        }
        $this->job($foreignMission, $identity['foreign_user_id'], 'running');

        return $identity + [
            'enabled_service_id' => $enabledServiceId,
            'disabled_service_id' => $disabledServiceId,
        ];
    }

    /** @return array<string, string> */
    private function createIdentity(
        bool $permission = true,
        bool $foreignPermission = false,
        string $prefix = '',
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'AI Admin Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign AI Admin Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actorId, $organizationId, $prefix.'ai-admin@example.test');
        $this->user($foreignUserId, $foreignOrganizationId, $prefix.'foreign-ai-admin@example.test');

        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRoleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'AI Admin', 'role_code' => $prefix.'ai_admin', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign AI Admin', 'role_code' => $prefix.'foreign_ai_admin', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'ai_services.manage')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore([
            'permission_id' => $permissionId, 'permission_code' => 'ai_services.manage',
            'permission_name' => 'Manage AI services', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($permission || $foreignPermission) {
            $roleId = $foreignPermission ? $foreignRoleId : $localRoleId;
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [
            'actor_id' => $actorId,
            'organization_id' => $organizationId,
            'foreign_user_id' => $foreignUserId,
            'foreign_organization_id' => $foreignOrganizationId,
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'ai-overview')->plainTextToken,
        ];
    }

    private function user(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId,
            'first_name' => 'AI', 'last_name' => 'Administrator', 'email' => $email,
            'password' => Hash::make('password'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function model(string $creatorId, string $name, bool $deleted): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_models')->insert([
            'model_id' => $id, 'model_name' => $name, 'model_type' => 'tree_detector',
            'framework' => 'YOLO', 'created_by' => $creatorId,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null,
        ]);

        return $id;
    }

    private function version(string $modelId, string $label, bool $deployed): void
    {
        DB::table('ai_model_versions')->insert([
            'model_version_id' => (string) Str::uuid(), 'model_id' => $modelId,
            'version_label' => $label, 'model_file_path' => 'private/models/'.$modelId.'/'.$label.'.pt',
            'is_deployed' => $deployed, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function site(string $organizationId, string $creatorId, string $code): string
    {
        $id = (string) Str::uuid();
        DB::table('survey_sites')->insert([
            'site_id' => $id, 'organization_id' => $organizationId,
            'site_name' => $code.' Site', 'site_code' => $code.'-SITE',
            'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City',
            'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $creatorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function mission(string $siteId, string $creatorId, string $code): string
    {
        $id = (string) Str::uuid();
        DB::table('survey_missions')->insert([
            'mission_id' => $id, 'site_id' => $siteId, 'mission_code' => $code.'-MISSION',
            'mission_title' => $code.' Mission', 'mission_objective' => 'AI overview.',
            'mission_status' => 'completed', 'created_by' => $creatorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function job(string $missionId, string $creatorId, string $status): void
    {
        $startedAt = $status === 'queued' ? null : now();
        DB::table('processing_jobs')->insert([
            'processing_job_id' => (string) Str::uuid(), 'mission_id' => $missionId,
            'job_type' => 'detection', 'job_status' => $status,
            'started_at' => $startedAt,
            'completed_at' => in_array($status, ['completed', 'failed'], true) ? $startedAt : null,
            'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
