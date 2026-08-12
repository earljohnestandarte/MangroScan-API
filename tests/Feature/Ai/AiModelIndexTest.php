<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiModelIndexTest extends TestCase
{
    use RefreshDatabase;

    // [MODEL-01] Authorized readers receive the global base-model registry in stable order.
    public function test_it_lists_global_models_with_exact_safe_fields(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_model_01')
            ->getJson('/api/v1/ai-models');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_model_01')
            ->assertJsonPath('meta.request_id', 'req_model_01')
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.model_id', $graph['age_model_id'])
            ->assertJsonPath('data.3.model_id', $graph['tree_model_id'])
            ->assertJsonPath('data.3.model_type', 'tree_detector')
            ->assertJsonPath('data.3.created_at', '2026-08-12T01:00:00+00:00');

        $this->assertSame([
            'model_id', 'model_name', 'model_type', 'framework', 'description',
            'created_by', 'created_at', 'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertContains($graph['foreign_model_id'], $response->json('data.*.model_id'));
        $this->assertNotContains($graph['deleted_model_id'], $response->json('data.*.model_id'));
        $this->assertArrayNotHasKey('versions', $response->json('data.0'));
        $this->assertArrayNotHasKey('model_file_path', $response->json('data.0'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [MODEL-01] Type and deployed-version filters compose after normalization.
    public function test_it_filters_models_by_type_and_deployment_existence(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->getJson('/api/v1/ai-models?type=%20TREE_DETECTOR%20&deployed=true')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.model_id', $graph['tree_model_id']);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/ai-models?deployed=false')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.model_id', $graph['age_model_id'])
            ->assertJsonPath('data.1.model_id', $graph['height_model_id']);
    }

    // [MODEL-01] Unknown model types and unsafe booleans fail validation.
    public function test_it_validates_model_filters(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->getJson('/api/v1/ai-models?type=pipeline&deployed=sometimes')
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['type', 'deployed'], 'error.details');
    }

    // [MODEL-01] Authentication and a current/global ai_models.read grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $this->getJson('/api/v1/ai-models')->assertUnauthorized();

        $missing = $this->createGraph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->getJson('/api/v1/ai-models')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'ai_models.read');

        $foreign = $this->createGraph(foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->getJson('/api/v1/ai-models')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'ai_models.read');
    }

    // [MODEL-01] Inactive identities cannot inspect the model registry.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($graph['token'])->getJson('/api/v1/ai-models')->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [MODEL-01] Registry reads use the shared authenticated request budget.
    public function test_it_rate_limits_model_registry_reads(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->getJson('/api/v1/ai-models')->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/ai-models')->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [MODEL-01] Registry/version provenance and read-only DCL are versioned without a P2 route.
    public function test_it_versions_model_registry_schema_and_read_only_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_064600_create_ai_model_registry_tables.php'));
        $dcl = file_get_contents(database_path('sql/dcl/026_ai_model_registry_grants.sql'));

        $this->assertIsString($migration);
        foreach (['training_datasets', 'ai_models', 'ai_model_versions', 'ai_models_type_check'] as $object) {
            $this->assertStringContainsString($object, $migration);
        }
        $this->assertStringContainsString("->unique(['model_id', 'version_label'])", $migration);
        $this->assertStringContainsString("->index(['model_id', 'is_deployed'])", $migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.ai_models, app.ai_model_versions', $dcl);
        $this->assertStringContainsString('TO mangroscan_api_rw, mangroscan_report_ro;', $dcl);
        foreach (['INSERT', 'UPDATE', 'DELETE', 'training_datasets', 'mangroscan_worker', 'mangroscan_auditor'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    // [MODEL-01] PostgreSQL enforces model type and version uniqueness invariants.
    public function test_postgresql_enforces_model_registry_invariants(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL model-registry verification.');
        }

        $graph = $this->createGraph(prefix: 'constraint-');
        $this->expectException(QueryException::class);
        DB::table('ai_models')->where('model_id', $graph['tree_model_id'])
            ->update(['model_type' => 'combined_pipeline']);
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
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'AI Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign AI Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actorId, $organizationId, $prefix.'model-reader@example.test');
        $this->user($foreignUserId, $foreignOrganizationId, $prefix.'foreign-model-reader@example.test');

        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRoleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Model Reader', 'role_code' => $prefix.'model_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Model Reader', 'role_code' => $prefix.'foreign_model_reader', 'created_at' => now(), 'updated_at' => now()],
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

        $ageModelId = $this->model($actorId, 'Age Estimator', 'age_estimator', 'OpenCV', '2026-08-12T04:00:00+00:00');
        $foreignModelId = $this->model($foreignUserId, 'Species Classifier', 'species_classifier', 'TensorFlow', '2026-08-12T03:00:00+00:00');
        $heightModelId = $this->model($actorId, 'Height Estimator', 'height_estimator', null, '2026-08-12T02:00:00+00:00');
        $treeModelId = $this->model($actorId, 'Tree Detector', 'tree_detector', 'YOLO', '2026-08-12T01:00:00+00:00');
        $deletedModelId = $this->model($actorId, 'Unused Detector', 'tree_detector', 'YOLO', '2026-08-12T05:00:00+00:00', true);

        $this->version($treeModelId, 'v1.0', false);
        $this->version($treeModelId, 'v1.1', true);
        $this->version($foreignModelId, 'v2.0', true);
        $this->version($heightModelId, 'v1.0', false);

        return [
            'actor_id' => $actorId,
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'model-index')->plainTextToken,
            'age_model_id' => $ageModelId,
            'foreign_model_id' => $foreignModelId,
            'height_model_id' => $heightModelId,
            'tree_model_id' => $treeModelId,
            'deleted_model_id' => $deletedModelId,
        ];
    }

    private function user(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId,
            'first_name' => 'Model', 'last_name' => 'Reader', 'email' => $email,
            'password' => Hash::make('password'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function model(
        string $creatorId,
        string $name,
        string $type,
        ?string $framework,
        string $createdAt,
        bool $deleted = false,
    ): string {
        $id = (string) Str::uuid();
        DB::table('ai_models')->insert([
            'model_id' => $id, 'model_name' => $name, 'model_type' => $type,
            'framework' => $framework, 'description' => $name.' model.',
            'created_by' => $creatorId, 'created_at' => $createdAt, 'updated_at' => $createdAt,
            'deleted_at' => $deleted ? $createdAt : null,
        ]);

        return $id;
    }

    private function version(string $modelId, string $label, bool $deployed): void
    {
        DB::table('ai_model_versions')->insert([
            'model_version_id' => (string) Str::uuid(), 'model_id' => $modelId,
            'version_label' => $label, 'model_file_path' => 'private/models/'.$modelId.'/'.$label.'.bin',
            'accuracy' => '0.9000', 'is_deployed' => $deployed,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
