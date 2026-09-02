<?php

namespace Tests\Feature\Media;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsApiIdentity;
use Tests\TestCase;

class MediaDeleteTest extends TestCase
{
    use BuildsApiIdentity;
    use RefreshDatabase;

    public function test_it_soft_deletes_unneeded_media_with_audit(): void
    {
        $graph = $this->mediaGraph('media-delete-', ['media.delete']);

        $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_media_07')
            ->deleteJson('/api/v1/media/'.$graph['media_id'])
            ->assertNoContent();

        $this->assertSoftDeleted('media_assets', ['media_asset_id' => $graph['media_id']]);
        $this->assertDatabaseHas('media_assets', [
            'media_asset_id' => $graph['media_id'],
            'sync_version' => 2,
        ]);
        $audit = AuditLog::query()->sole();
        $this->assertSame('media.delete', $audit->action);
        $this->assertSame('req_media_07', $audit->request_id);
    }

    public function test_it_rejects_media_with_downstream_dependencies(): void
    {
        $graph = $this->mediaGraph('media-dependent-', ['media.delete'], processingStatus: 'queued');

        $this->withToken($graph['token'])->deleteJson('/api/v1/media/'.$graph['media_id'])
            ->assertConflict()
            ->assertJsonPath('error.details.dependencies.0', 'active_processing');
        $this->assertDatabaseHas('media_assets', [
            'media_asset_id' => $graph['media_id'],
            'deleted_at' => null,
        ]);
    }

    public function test_it_enforces_access_scope_and_versions_narrow_dcl(): void
    {
        $graph = $this->mediaGraph('media-delete-access-', [], processingStatus: 'completed');
        $this->deleteJson('/api/v1/media/'.$graph['media_id'])->assertUnauthorized();
        $this->withToken($graph['token'])->deleteJson('/api/v1/media/'.$graph['media_id'])->assertForbidden();

        $authorized = $this->mediaGraph('media-delete-authorized-', ['media.delete'], processingStatus: 'completed');
        $this->app['auth']->forgetGuards();
        $this->withToken($authorized['token'])->deleteJson('/api/v1/media/'.$graph['media_id'])->assertNotFound();

        $dcl = file_get_contents(database_path('sql/dcl/062_jessamae_endpoint_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString(
            'GRANT UPDATE (sync_version, deleted_at, updated_at) ON TABLE app.media_assets TO mangroscan_api_rw;',
            $dcl,
        );
        $this->assertStringNotContainsString('GRANT DELETE ON TABLE app.media_assets', $dcl);
    }

    /** @param list<string> $permissions @return array{media_id:string,token:string} */
    private function mediaGraph(
        string $prefix,
        array $permissions,
        string $processingStatus = 'pending',
    ): array {
        $identity = $this->apiIdentity($permissions, $prefix);
        $lineage = $this->missionLineage($identity['organization_id'], $identity['actor_id'], Str::upper($prefix));
        $mediaId = (string) Str::uuid();
        DB::table('media_assets')->insert([
            'media_asset_id' => $mediaId,
            'flight_session_id' => $lineage['flight_id'],
            'uploaded_by_user_id' => $identity['actor_id'],
            'file_name' => 'delete.jpg',
            'file_type' => 'image',
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => 128,
            'storage_key' => 'private/'.$mediaId.'/delete.jpg',
            'quality_status' => 'pending',
            'processing_status' => $processingStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['media_id' => $mediaId, 'token' => $identity['token']];
    }
}
