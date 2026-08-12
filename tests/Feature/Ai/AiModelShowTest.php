<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiModelShowTest extends TestCase
{
    use RefreshDatabase;

    // [MODEL-02] Model detail returns safe registry data and ordered version provenance.
    public function test_it_returns_model_detail_and_safe_versions(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_model_02')
            ->getJson('/api/v1/ai-models/'.$graph['model_id']);

        $response->assertOk()->assertHeader('X-Request-ID', 'req_model_02')
            ->assertJsonPath('data.model.model_id', $graph['model_id'])
            ->assertJsonPath('data.model.model_type', 'tree_detector')
            ->assertJsonCount(3, 'data.versions')
            ->assertJsonPath('data.versions.0.model_version_id', $graph['deployed_version_id'])
            ->assertJsonPath('data.versions.0.is_deployed', true)
            ->assertJsonPath('data.versions.0.accuracy', '0.9500')
            ->assertJsonPath('data.versions.0.training_dataset_id', $graph['dataset_id'])
            ->assertJsonPath('data.versions.0.created_at', '2026-08-12T02:00:00+00:00')
            ->assertJsonPath('data.versions.1.model_version_id', $graph['newest_undeployed_version_id'])
            ->assertJsonPath('meta.request_id', 'req_model_02');

        $this->assertSame([
            'model_version_id', 'model_id', 'version_label', 'training_dataset_id',
            'accuracy', 'precision_score', 'recall_score', 'f1_score', 'rmse',
            'is_deployed', 'release_notes', 'created_at', 'updated_at',
        ], array_keys($response->json('data.versions.0')));
        foreach ($response->json('data.versions') as $version) {
            $this->assertArrayNotHasKey('model_file_path', $version);
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [MODEL-02] Empty version history remains an explicit empty array.
    public function test_it_returns_an_empty_version_array(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->getJson('/api/v1/ai-models/'.$graph['empty_model_id'])
            ->assertOk()->assertJsonPath('data.versions', []);
    }

    // [MODEL-02] Missing, malformed and soft-deleted model identifiers return 404.
    public function test_it_hides_unavailable_models(): void
    {
        $graph = $this->createGraph();

        foreach ([$graph['deleted_model_id'], (string) Str::uuid()] as $modelId) {
            $this->withToken($graph['token'])->getJson('/api/v1/ai-models/'.$modelId)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $this->withToken($graph['token'])->getJson('/api/v1/ai-models/not-a-uuid')->assertNotFound();
    }

    // [MODEL-02] Authentication and a current/global ai_models.read grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $graph = $this->createGraph(prefix: 'auth-');
        $this->getJson('/api/v1/ai-models/'.$graph['model_id'])->assertUnauthorized();

        $missing = $this->createGraph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->getJson('/api/v1/ai-models/'.$missing['model_id'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'ai_models.read');

        $foreign = $this->createGraph(foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->getJson('/api/v1/ai-models/'.$foreign['model_id'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'ai_models.read');
    }

    // [MODEL-02] Inactive identities cannot inspect model provenance.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($graph['token'])->getJson('/api/v1/ai-models/'.$graph['model_id'])
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [MODEL-02] Detail reads use the shared authenticated request budget.
    public function test_it_rate_limits_model_detail_reads(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->getJson('/api/v1/ai-models/'.$graph['model_id'])->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/ai-models/'.$graph['model_id'])
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [MODEL-02] Existing registry DCL supports detail without widening privileges.
    public function test_it_reuses_read_only_model_registry_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/026_ai_model_registry_grants.sql'));

        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.ai_models, app.ai_model_versions', $dcl);
        foreach (['INSERT', 'UPDATE', 'DELETE', 'training_datasets'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
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
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Model Detail Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Model Detail Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actorId, $organizationId, $prefix.'model-detail@example.test');
        $this->user($foreignUserId, $foreignOrganizationId, $prefix.'foreign-model-detail@example.test');

        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRoleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Model Detail Reader', 'role_code' => $prefix.'model_detail_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Model Detail Reader', 'role_code' => $prefix.'foreign_model_detail_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'ai_models.read')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore([
            'permission_id' => $permissionId, 'permission_code' => 'ai_models.read',
            'permission_name' => 'Read AI models', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($permission || $foreignPermission) {
            $roleId = $foreignPermission ? $foreignRoleId : $localRoleId;
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        }

        $datasetId = (string) Str::uuid();
        DB::table('training_datasets')->insert([
            'training_dataset_id' => $datasetId, 'dataset_name' => 'Tree Dataset',
            'dataset_type' => 'detection', 'source' => 'manually_labeled',
            'version_label' => 'v1', 'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $modelId = $this->model($actorId, $prefix.'Tree Detector', false);
        $emptyModelId = $this->model($foreignUserId, $prefix.'Empty Detector', false);
        $deletedModelId = $this->model($actorId, $prefix.'Deleted Detector', true);
        $this->version($modelId, 'v1.0', false, '2026-08-12T01:00:00+00:00', null);
        $deployedVersionId = $this->version($modelId, 'v1.1', true, '2026-08-12T02:00:00+00:00', $datasetId);
        $newestUndeployedVersionId = $this->version($modelId, 'v1.2-rc', false, '2026-08-12T03:00:00+00:00', null);

        return [
            'actor_id' => $actorId,
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'model-show')->plainTextToken,
            'dataset_id' => $datasetId,
            'model_id' => $modelId,
            'empty_model_id' => $emptyModelId,
            'deleted_model_id' => $deletedModelId,
            'deployed_version_id' => $deployedVersionId,
            'newest_undeployed_version_id' => $newestUndeployedVersionId,
        ];
    }

    private function user(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId,
            'first_name' => 'Model', 'last_name' => 'Detail', 'email' => $email,
            'password' => Hash::make('password'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function model(string $creatorId, string $name, bool $deleted): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_models')->insert([
            'model_id' => $id, 'model_name' => $name, 'model_type' => 'tree_detector',
            'framework' => 'YOLO', 'description' => 'Tree detection model.',
            'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);

        return $id;
    }

    private function version(
        string $modelId,
        string $label,
        bool $deployed,
        string $createdAt,
        ?string $datasetId,
    ): string {
        $id = (string) Str::uuid();
        DB::table('ai_model_versions')->insert([
            'model_version_id' => $id, 'model_id' => $modelId, 'version_label' => $label,
            'model_file_path' => 'private/models/'.$modelId.'/'.$label.'.pt',
            'training_dataset_id' => $datasetId, 'accuracy' => '0.9500',
            'precision_score' => '0.9400', 'recall_score' => '0.9300', 'f1_score' => '0.9350',
            'rmse' => null, 'is_deployed' => $deployed, 'release_notes' => 'Version '.$label,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        return $id;
    }
}
