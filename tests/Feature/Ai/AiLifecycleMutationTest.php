<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsApiIdentity;
use Tests\TestCase;

class AiLifecycleMutationTest extends TestCase
{
    use BuildsApiIdentity;
    use RefreshDatabase;

    // [AISVC-05] Credential rotation is encrypted, secret-free, and exact 204.
    public function test_it_rotates_ai_service_credentials(): void
    {
        $identity = $this->apiIdentity(['ai_services.manage']);
        $serviceId = (string) Str::uuid();
        DB::table('ai_services')->insert([
            'ai_service_id' => $serviceId, 'service_name' => 'Inference',
            'base_url' => 'https://rotate.example.test', 'encrypted_api_key' => Crypt::encryptString('old-secret'),
            'environment' => 'production', 'enabled' => true, 'health_status' => 'healthy',
            'created_by' => $identity['actor_id'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($identity['token'])->withHeader('X-Request-ID', 'req_aisvc_05')
            ->postJson('/api/v1/admin/ai-services/'.$serviceId.'/credentials', ['api_key' => ' new-secret '])
            ->assertNoContent();

        $stored = DB::table('ai_services')->where('ai_service_id', $serviceId)->value('encrypted_api_key');
        $this->assertSame('new-secret', Crypt::decryptString($stored));
        $audit = DB::table('audit_logs')->where('action', 'ai_service.credentials.rotate')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('new-secret', (string) $audit->new_values);

        $this->withToken($identity['token'])
            ->postJson('/api/v1/admin/ai-services/'.$serviceId.'/credentials', ['api_key' => 'new-secret'])
            ->assertConflict();
    }

    // [MODEL-03] Deployment selects one validated model version atomically.
    public function test_it_deploys_one_validated_model_version(): void
    {
        $identity = $this->apiIdentity(['ai_models.manage'], 'deploy-');
        $modelId = (string) Str::uuid();
        DB::table('ai_models')->insert([
            'model_id' => $modelId, 'model_name' => 'Detector', 'model_type' => 'tree_detector',
            'created_by' => $identity['actor_id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldId = $this->version($modelId, 'v1', true, '0.8000');
        $newId = $this->version($modelId, 'v2', false, '0.9500');
        $unvalidatedId = $this->version($modelId, 'draft', false, null);

        $this->withToken($identity['token'])
            ->postJson('/api/v1/ai-models/'.$modelId.'/versions/'.$newId.'/deploy', ['release_notes' => ' Production candidate. '])
            ->assertOk()->assertJsonPath('data.model_version_id', $newId)
            ->assertJsonPath('data.is_deployed', true)->assertJsonPath('data.release_notes', 'Production candidate.');
        $this->assertDatabaseHas('ai_model_versions', ['model_version_id' => $oldId, 'is_deployed' => false]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai_model_version.deploy', 'record_id' => $newId]);

        $this->withToken($identity['token'])
            ->postJson('/api/v1/ai-models/'.$modelId.'/versions/'.$unvalidatedId.'/deploy')
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
    }

    public function test_ai_lifecycle_mutations_enforce_existing_permissions_and_narrow_dcl(): void
    {
        $this->postJson('/api/v1/admin/ai-services/'.Str::uuid().'/credentials', ['api_key' => 'secret'])->assertUnauthorized();
        $identity = $this->apiIdentity([], 'no-ai-');
        $this->withToken($identity['token'])
            ->postJson('/api/v1/admin/ai-services/'.Str::uuid().'/credentials', ['api_key' => 'secret'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'ai_services.manage');

        $dcl = file_get_contents(database_path('sql/dcl/046_ai_lifecycle_write_grants.sql'));
        $this->assertStringContainsString('UPDATE (encrypted_api_key, updated_at)', $dcl);
        $this->assertStringContainsString('UPDATE (is_deployed, release_notes, updated_at)', $dcl);
        $this->assertStringNotContainsString('mangroscan_report_ro', $dcl);
    }

    private function version(string $modelId, string $label, bool $deployed, ?string $accuracy): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_model_versions')->insert([
            'model_version_id' => $id, 'model_id' => $modelId, 'version_label' => $label,
            'model_file_path' => 'private/'.$id.'.pt', 'accuracy' => $accuracy,
            'is_deployed' => $deployed, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
