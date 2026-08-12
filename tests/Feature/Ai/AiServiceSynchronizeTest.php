<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiServiceSynchronizeTest extends TestCase
{
    use RefreshDatabase;

    // [AISVC-04] Synchronization creates safe model/version provenance and service capabilities.
    public function test_it_synchronizes_authoritative_model_metadata(): void
    {
        Http::fake(['*/models' => Http::response($this->metadata())]);
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_aisvc_04')
            ->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/synchronize');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_aisvc_04')
            ->assertJsonPath('data.models_synced', 2)
            ->assertJsonPath('data.capabilities.detection', true)
            ->assertJsonPath('data.capabilities.max_batch_size', 16)
            ->assertJsonPath('meta.request_id', 'req_aisvc_04');
        $this->assertSame(['models_synced', 'capabilities'], array_keys($response->json('data')));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sync.example.test/models'
            && $request->hasHeader('X-API-Key', 'synchronization-secret')
        );
        $this->assertDatabaseCount('ai_models', 2);
        $this->assertDatabaseCount('ai_model_versions', 3);
        $detector = DB::table('ai_models')->where('external_model_key', 'tree-detector')->first();
        $this->assertNotNull($detector);
        $this->assertSame($graph['service_id'], $detector->ai_service_id);
        $this->assertSame('tree_detector', $detector->model_type);
        $this->assertDatabaseHas('ai_model_versions', [
            'model_id' => $detector->model_id, 'version_label' => 'v2.0',
            'model_file_path' => 'models/tree-detector/v2.0.pt', 'is_deployed' => false,
        ]);
        $this->assertNotNull(DB::table('ai_services')->where('ai_service_id', $graph['service_id'])->value('last_synchronized_at'));
        $this->assertSame(
            ['detection' => true, 'classification' => true, 'max_batch_size' => 16],
            json_decode((string) DB::table('ai_services')->where('ai_service_id', $graph['service_id'])->value('capabilities'), true, 512, JSON_THROW_ON_ERROR),
        );

        $audit = DB::table('audit_logs')->where('action', 'ai_service.synchronize')->first();
        $this->assertNotNull($audit);
        $this->assertSame('req_aisvc_04', $audit->request_id);
        $this->assertStringNotContainsString('synchronization-secret', (string) $audit->new_values);
        $this->assertStringNotContainsString('artifact_ref', (string) $audit->new_values);
        $this->assertStringNotContainsString('model_file_path', (string) $audit->new_values);
    }

    // [AISVC-04] Repeated synchronization updates metadata without duplicates or deployment changes.
    public function test_it_idempotently_updates_existing_models_and_versions(): void
    {
        $graph = $this->createGraph();
        $updated = $this->metadata();
        $updated['models'][0]['name'] = 'Updated Tree Detector';
        $updated['models'][0]['versions'][1]['accuracy'] = 0.97;
        $updated['models'][0]['versions'][1]['artifact_ref'] = 'models/tree-detector/v2.0-repacked.pt';
        Http::fake(['*/models' => Http::sequence()
            ->push($this->metadata())
            ->push($updated)]);
        $this->withToken($graph['token'])->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/synchronize')->assertOk();
        $modelId = DB::table('ai_models')->where('external_model_key', 'tree-detector')->value('model_id');
        DB::table('ai_model_versions')->where('model_id', $modelId)->where('version_label', 'v2.0')->update(['is_deployed' => true]);

        $this->withToken($graph['token'])->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/synchronize')
            ->assertOk()->assertJsonPath('data.models_synced', 2);

        $this->assertDatabaseCount('ai_models', 2);
        $this->assertDatabaseCount('ai_model_versions', 3);
        $this->assertDatabaseHas('ai_models', ['model_id' => $modelId, 'model_name' => 'Updated Tree Detector']);
        $this->assertDatabaseHas('ai_model_versions', [
            'model_id' => $modelId, 'version_label' => 'v2.0',
            'accuracy' => '0.9700', 'model_file_path' => 'models/tree-detector/v2.0-repacked.pt',
            'is_deployed' => true,
        ]);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    // [AISVC-04] Only enabled services with successful health evidence can synchronize.
    public function test_it_enforces_service_readiness_and_identifier_boundaries(): void
    {
        Http::fake();
        foreach ([
            ['enabled' => false, 'health' => 'healthy'],
            ['enabled' => true, 'health' => 'unavailable'],
        ] as $case) {
            $graph = $this->createGraph(enabled: $case['enabled'], health: $case['health'], prefix: $case['health'].($case['enabled'] ? '-on-' : '-off-'));
            $this->withToken($graph['token'])->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/synchronize')
                ->assertConflict()->assertJsonPath('error.code', 'CONFLICT')
                ->assertJsonPath('error.details.health_status', $case['health']);
        }

        $graph = $this->createGraph(prefix: 'ids-');
        $this->withToken($graph['token'])->postJson('/api/v1/admin/ai-services/'.Str::uuid().'/synchronize')->assertNotFound();
        $this->withToken($graph['token'])->postJson('/api/v1/admin/ai-services/nope/synchronize')->assertNotFound();
        Http::assertNothingSent();
    }

    // [AISVC-04] Authentication and a tenant-valid administrator grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        Http::fake();
        $auth = $this->createGraph(prefix: 'auth-');
        $this->postJson('/api/v1/admin/ai-services/'.$auth['service_id'].'/synchronize')->assertUnauthorized();

        $missing = $this->createGraph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->postJson('/api/v1/admin/ai-services/'.$missing['service_id'].'/synchronize')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'ai_services.manage');

        $foreign = $this->createGraph(foreignPermission: true, prefix: 'foreign-');
        $this->withToken($foreign['token'])->postJson('/api/v1/admin/ai-services/'.$foreign['service_id'].'/synchronize')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'ai_services.manage');

        Http::assertNothingSent();
    }

    // [AISVC-04] Inactive administrators cannot synchronize metadata.
    public function test_it_rejects_an_inactive_identity(): void
    {
        Http::fake();
        $inactive = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($inactive['token'])->postJson('/api/v1/admin/ai-services/'.$inactive['service_id'].'/synchronize')
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
        Http::assertNothingSent();
    }

    // [AISVC-04] Invalid downstream metadata maps to 502 with no partial registry writes.
    public function test_it_rejects_invalid_downstream_metadata_atomically(): void
    {
        $invalidCases = [
            ['capabilities' => [], 'models' => [['key' => 'x', 'name' => 'X', 'type' => 'pipeline', 'versions' => []]]],
            ['capabilities' => [], 'models' => [['key' => 'x', 'name' => 'X', 'type' => 'tree_detector', 'versions' => [['label' => 'v1', 'artifact_ref' => 'x', 'accuracy' => 2]]]]],
            ['capabilities' => ['nested' => ['not' => 'allowed']], 'models' => []],
        ];

        foreach ($invalidCases as $index => $payload) {
            Http::fake(['*/models' => Http::response($payload)]);
            $graph = $this->createGraph(prefix: 'invalid-'.$index.'-');
            $this->withToken($graph['token'])->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/synchronize')
                ->assertStatus(502)->assertJsonPath('error.code', 'BAD_GATEWAY');
        }

        $this->assertDatabaseCount('ai_models', 0);
        $this->assertDatabaseCount('ai_model_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [AISVC-04] HTTP and transport failures map to 503 without leaking downstream content.
    public function test_it_normalizes_downstream_failures(): void
    {
        foreach (['http', 'transport'] as $case) {
            Http::fake(function () use ($case) {
                if ($case === 'transport') {
                    throw new ConnectionException('synchronization-secret transport failure');
                }

                return Http::response(['detail' => 'synchronization-secret rejected'], 500);
            });
            $graph = $this->createGraph(prefix: $case.'-');
            $response = $this->withToken($graph['token'])
                ->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/synchronize');
            $response->assertServiceUnavailable()->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
            $this->assertStringNotContainsString('synchronization-secret', $response->getContent());
        }
        $this->assertDatabaseCount('ai_models', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [AISVC-04] Synchronization is rate-limited before a duplicate downstream call.
    public function test_it_rate_limits_synchronization(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        Http::fake(['*/models' => Http::response($this->metadata())]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/synchronize')->assertOk();
        $this->withToken($graph['token'])->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/synchronize')
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
        Http::assertSentCount(1);
    }

    // [AISVC-04] Registry synchronization has only the model and sync-column writes it needs.
    public function test_it_versions_provenance_schema_and_narrow_write_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_064900_add_service_provenance_to_ai_models.php'));
        $dcl = file_get_contents(database_path('sql/dcl/030_ai_service_synchronization_grants.sql'));
        $this->assertIsString($migration);
        foreach (['ai_service_id', 'external_model_key', "->unique(['ai_service_id', 'external_model_key'])"] as $object) {
            $this->assertStringContainsString($object, $migration);
        }
        $this->assertIsString($dcl);
        foreach (['GRANT INSERT (', 'GRANT UPDATE (', 'app.ai_models', 'app.ai_model_versions', 'app.ai_services'] as $grant) {
            $this->assertStringContainsString($grant, $dcl);
        }
        foreach (['encrypted_api_key', 'is_deployed,', 'mangroscan_report_ro', 'mangroscan_worker', 'DELETE'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return [
            'capabilities' => ['detection' => true, 'classification' => true, 'max_batch_size' => 16],
            'models' => [
                [
                    'key' => 'tree-detector', 'name' => 'Tree Detector', 'type' => 'tree_detector',
                    'framework' => 'YOLO', 'description' => 'Detects mangrove trees.',
                    'versions' => [
                        ['label' => 'v1.0', 'artifact_ref' => 'models/tree-detector/v1.0.pt', 'accuracy' => 0.91, 'precision_score' => 0.9, 'recall_score' => 0.89, 'f1_score' => 0.895, 'rmse' => null, 'release_notes' => 'Initial.'],
                        ['label' => 'v2.0', 'artifact_ref' => 'models/tree-detector/v2.0.pt', 'accuracy' => 0.96, 'precision_score' => 0.95, 'recall_score' => 0.94, 'f1_score' => 0.945, 'rmse' => null, 'release_notes' => 'Improved.'],
                    ],
                ],
                [
                    'key' => 'species-classifier', 'name' => 'Species Classifier', 'type' => 'species_classifier',
                    'framework' => 'TensorFlow', 'description' => null,
                    'versions' => [
                        ['label' => 'v3', 'artifact_ref' => 'models/species/v3.keras', 'accuracy' => 0.93, 'precision_score' => null, 'recall_score' => null, 'f1_score' => null, 'rmse' => null, 'release_notes' => null],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, string> */
    private function createGraph(
        bool $permission = true,
        bool $foreignPermission = false,
        bool $enabled = true,
        string $health = 'healthy',
        string $prefix = '',
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Sync Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Sync Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actorId, $organizationId, $prefix.'sync@example.test');
        $this->user($foreignUserId, $foreignOrganizationId, $prefix.'foreign-sync@example.test');
        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRoleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Sync Admin', 'role_code' => $prefix.'sync_admin', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Sync Admin', 'role_code' => $prefix.'foreign_sync_admin', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'ai_services.manage')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'ai_services.manage', 'permission_name' => 'Manage AI services', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission || $foreignPermission) {
            $roleId = $foreignPermission ? $foreignRoleId : $localRoleId;
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        }
        $serviceId = (string) Str::uuid();
        DB::table('ai_services')->insert([
            'ai_service_id' => $serviceId, 'service_name' => $prefix.'Sync Service',
            'base_url' => 'https://'.($prefix === '' ? '' : rtrim($prefix, '-').'.').'sync.example.test',
            'encrypted_api_key' => Crypt::encryptString('synchronization-secret'),
            'environment' => $prefix === '' ? 'production' : substr($prefix, 0, 49),
            'enabled' => $enabled, 'health_status' => $health, 'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'actor_id' => $actorId, 'service_id' => $serviceId,
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'ai-sync')->plainTextToken,
        ];
    }

    private function user(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organizationId, 'first_name' => 'AI', 'last_name' => 'Sync', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }
}
