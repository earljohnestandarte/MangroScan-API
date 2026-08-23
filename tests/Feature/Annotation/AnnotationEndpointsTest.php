<?php

namespace Tests\Feature\Annotation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuildsApiIdentity;
use Tests\TestCase;

class AnnotationEndpointsTest extends TestCase
{
    use BuildsApiIdentity;
    use RefreshDatabase;

    // [ANN-01, ANN-02] Projects are created and listed inside organization scope.
    public function test_it_creates_and_lists_annotation_projects(): void
    {
        $identity = $this->apiIdentity(['ai_models.read', 'ai_models.manage']);
        $response = $this->withToken($identity['token'])->postJson('/api/v1/annotation/projects', [
            'name' => ' Coastal Annotation ', 'dataset_type' => ' Detection ', 'status' => ' PLANNED ',
        ]);
        $response->assertCreated()->assertJsonPath('data.name', 'Coastal Annotation')
            ->assertJsonPath('data.dataset_type', 'detection')->assertJsonPath('data.status', 'planned');
        $projectId = $response->json('data.annotation_project_id');
        $this->assertDatabaseHas('audit_logs', ['action' => 'annotation_project.create', 'record_id' => $projectId]);

        $foreign = $this->apiIdentity([], 'foreign-project-');
        DB::table('annotation_projects')->insert([
            'annotation_project_id' => (string) Str::uuid(), 'organization_id' => $foreign['organization_id'],
            'name' => 'Foreign Project', 'dataset_type' => 'detection', 'status' => 'planned',
            'created_by' => $foreign['actor_id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($identity['token'])->getJson('/api/v1/annotation/projects?status=planned')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.annotation_project_id', $projectId);

        $this->withToken($identity['token'])->postJson('/api/v1/annotation/projects', [
            'name' => 'coastal annotation', 'dataset_type' => 'classification', 'status' => 'active',
        ])->assertConflict();
    }

    // [ANN-03, ANN-04] Object replacement is atomic and exports a private artifact.
    public function test_it_replaces_objects_and_exports_the_project(): void
    {
        Storage::fake('local');
        config(['mangroscan.media.disk' => 'local']);
        $identity = $this->apiIdentity(['ai_models.manage'], 'objects-');
        $projectId = $this->project($identity['organization_id'], $identity['actor_id'], 'Object Project');
        $itemId = (string) Str::uuid();
        DB::table('annotation_items')->insert([
            'annotation_item_id' => $itemId, 'annotation_project_id' => $projectId,
            'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $speciesId = (string) Str::uuid();
        DB::table('mangrove_species')->insert([
            'species_id' => $speciesId, 'scientific_name' => 'Avicennia exportensis',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $objects = [
            ['class_id' => $speciesId, 'bbox' => [0.1, 0.2, 0.3, 0.4], 'attributes' => ['health' => 'good']],
            ['class_id' => $speciesId, 'polygon' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0]]]]],
            ['class_id' => $speciesId],
        ];
        $this->withToken($identity['token'])->putJson('/api/v1/annotation/items/'.$itemId.'/objects', ['objects' => $objects])
            ->assertOk()->assertJsonPath('data.count', 3);
        $this->assertDatabaseCount('annotation_objects', 3);
        $this->assertDatabaseHas('annotation_items', ['annotation_item_id' => $itemId, 'status' => 'in_progress']);

        $export = $this->withToken($identity['token'])
            ->postJson('/api/v1/annotation/projects/'.$projectId.'/exports', ['format' => 'CSV']);
        $export->assertCreated()->assertJsonPath('data.file_name', fn (string $name): bool => str_ends_with($name, '.csv'));
        Storage::disk('local')->assertExists($export->json('data.storage_key'));
        $this->assertDatabaseHas('annotation_exports', [
            'annotation_export_id' => $export->json('data.export_id'), 'format' => 'csv',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'annotation_project.export']);
    }

    public function test_annotation_mutations_hide_foreign_resources_and_validate_formats(): void
    {
        $identity = $this->apiIdentity(['ai_models.manage'], 'local-ann-');
        $foreign = $this->apiIdentity([], 'foreign-ann-');
        $foreignProject = $this->project($foreign['organization_id'], $foreign['actor_id'], 'Foreign Annotation');
        $foreignItem = (string) Str::uuid();
        DB::table('annotation_items')->insert([
            'annotation_item_id' => $foreignItem, 'annotation_project_id' => $foreignProject,
            'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $speciesId = (string) Str::uuid();
        DB::table('mangrove_species')->insert([
            'species_id' => $speciesId, 'scientific_name' => 'Foreignia hiddenensis',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($identity['token'])->putJson('/api/v1/annotation/items/'.$foreignItem.'/objects', [
            'objects' => [['class_id' => $speciesId, 'attributes' => ['review' => 'hidden']]],
        ])
            ->assertNotFound();
        $this->withToken($identity['token'])->postJson('/api/v1/annotation/projects/'.$foreignProject.'/exports', ['format' => 'xml'])
            ->assertUnprocessable()->assertJsonValidationErrors(['format'], 'error.details');
    }

    public function test_annotation_endpoints_use_existing_permissions_and_versioned_dcl(): void
    {
        $this->getJson('/api/v1/annotation/projects')->assertUnauthorized();
        $identity = $this->apiIdentity([], 'no-ann-');
        $this->withToken($identity['token'])->postJson('/api/v1/annotation/projects', [
            'name' => 'Denied', 'dataset_type' => 'detection', 'status' => 'planned',
        ])->assertForbidden()->assertJsonPath('error.details.required_permission', 'ai_models.manage');
        $dcl = file_get_contents(database_path('sql/dcl/047_training_annotation_grants.sql'));
        $this->assertStringContainsString('app.annotation_projects, app.annotation_items, app.annotation_objects, app.annotation_exports', $dcl);
        $this->assertStringContainsString('GRANT DELETE ON TABLE app.annotation_objects', $dcl);
    }

    private function project(string $organizationId, string $actorId, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('annotation_projects')->insert([
            'annotation_project_id' => $id, 'organization_id' => $organizationId,
            'name' => $name, 'dataset_type' => 'detection', 'status' => 'active',
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
