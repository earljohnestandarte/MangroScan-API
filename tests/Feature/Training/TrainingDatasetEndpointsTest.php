<?php

namespace Tests\Feature\Training;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsApiIdentity;
use Tests\TestCase;

class TrainingDatasetEndpointsTest extends TestCase
{
    use BuildsApiIdentity;
    use RefreshDatabase;

    // [DATASET-01, DATASET-02] Dataset metadata is normalized, auditable, filterable, and paginated.
    public function test_it_creates_and_lists_training_datasets(): void
    {
        $identity = $this->apiIdentity(['ai_models.read', 'ai_models.manage']);
        $response = $this->withToken($identity['token'])->withHeader('X-Request-ID', 'req_dataset_02')
            ->postJson('/api/v1/training-datasets', [
                'dataset_name' => ' Coastal Trees ', 'dataset_type' => ' Detection ',
                'source' => ' Field Survey ', 'description' => ' Curated labels. ', 'version_label' => ' v1 ',
            ]);
        $response->assertCreated()->assertJsonPath('data.dataset_name', 'Coastal Trees')
            ->assertJsonPath('data.dataset_type', 'detection')->assertJsonPath('data.source', 'field survey')
            ->assertJsonPath('meta.request_id', 'req_dataset_02');
        $datasetId = $response->json('data.training_dataset_id');
        $this->assertDatabaseHas('audit_logs', ['action' => 'training_dataset.create', 'record_id' => $datasetId]);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/training-datasets?type=DETECTION&source=FIELD%20SURVEY')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.training_dataset_id', $datasetId)
            ->assertJsonPath('meta.total', 1);

        $this->withToken($identity['token'])->postJson('/api/v1/training-datasets', [
            'dataset_name' => 'coastal trees', 'dataset_type' => 'validation',
            'source' => 'manual', 'version_label' => 'V1',
        ])->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
    }

    // [DATASET-03] Labeled samples validate lineage and reject duplicate labels.
    public function test_it_attaches_a_tenant_visible_labeled_sample(): void
    {
        $identity = $this->apiIdentity(['ai_models.manage'], 'item-');
        $datasetId = $this->dataset($identity['actor_id'], 'Item Dataset');
        $lineage = $this->missionLineage($identity['organization_id'], $identity['actor_id'], 'DATAITEM');
        $mediaId = (string) Str::uuid();
        DB::table('media_assets')->insert([
            'media_asset_id' => $mediaId, 'flight_session_id' => $lineage['flight_id'],
            'uploaded_by_user_id' => $identity['actor_id'], 'file_name' => 'sample.jpg',
            'file_type' => 'image', 'mime_type' => 'image/jpeg', 'file_size_bytes' => 1024,
            'storage_key' => 'media/'.$mediaId.'.jpg', 'quality_status' => 'acceptable',
            'processing_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $speciesId = (string) Str::uuid();
        DB::table('mangrove_species')->insert([
            'species_id' => $speciesId, 'scientific_name' => 'Rhizophora itemensis',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payload = [
            'media_id' => $mediaId, 'label_file_path' => 'labels/sample.json',
            'label_format' => 'JSON', 'species_id' => $speciesId, 'annotation_status' => 'COMPLETED',
        ];

        $response = $this->withToken($identity['token'])
            ->postJson('/api/v1/training-datasets/'.$datasetId.'/items', $payload);
        $response->assertCreated()->assertJsonPath('data.media_id', $mediaId)
            ->assertJsonPath('data.label_format', 'json')->assertJsonPath('data.annotation_status', 'completed');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'training_dataset.item.create', 'record_id' => $response->json('data.dataset_item_id'),
        ]);
        $this->withToken($identity['token'])
            ->postJson('/api/v1/training-datasets/'.$datasetId.'/items', $payload)->assertConflict();

        $foreign = $this->apiIdentity([], 'foreign-item-');
        $foreignLineage = $this->missionLineage($foreign['organization_id'], $foreign['actor_id'], 'FOREIGNITEM');
        $foreignMedia = (string) Str::uuid();
        DB::table('media_assets')->insert([
            'media_asset_id' => $foreignMedia, 'flight_session_id' => $foreignLineage['flight_id'],
            'uploaded_by_user_id' => $foreign['actor_id'], 'file_name' => 'foreign.jpg',
            'file_type' => 'image', 'mime_type' => 'image/jpeg', 'file_size_bytes' => 1024,
            'storage_key' => 'media/'.$foreignMedia.'.jpg', 'quality_status' => 'acceptable',
            'processing_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($identity['token'])->postJson('/api/v1/training-datasets/'.$datasetId.'/items', [
            ...$payload, 'media_id' => $foreignMedia, 'label_file_path' => 'labels/foreign.json',
        ])->assertNotFound();
    }

    public function test_dataset_endpoints_enforce_permissions_and_version_the_extension(): void
    {
        $this->getJson('/api/v1/training-datasets')->assertUnauthorized();
        $identity = $this->apiIdentity([], 'no-dataset-');
        $this->withToken($identity['token'])->getJson('/api/v1/training-datasets')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'ai_models.read');

        $dcl = file_get_contents(database_path('sql/dcl/047_training_annotation_grants.sql'));
        $this->assertStringContainsString('app.training_datasets, app.training_dataset_items', $dcl);
        $migration = file_get_contents(database_path('migrations/2026_08_12_066200_create_training_annotation_extension_tables.php'));
        $this->assertStringContainsString("Schema::create('training_dataset_items'", $migration);
    }

    private function dataset(string $actorId, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('training_datasets')->insert([
            'training_dataset_id' => $id, 'dataset_name' => $name,
            'dataset_type' => 'training', 'source' => 'manual', 'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
