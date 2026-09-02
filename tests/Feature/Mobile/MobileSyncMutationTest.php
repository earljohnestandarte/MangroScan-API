<?php

namespace Tests\Feature\Mobile;

use App\Models\SyncDevice;
use App\Services\Mobile\SyncCursorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsApiIdentity;
use Tests\TestCase;

class MobileSyncMutationTest extends TestCase
{
    use BuildsApiIdentity;
    use RefreshDatabase;

    public function test_it_applies_a_checklist_and_returns_canonical_server_changes(): void
    {
        $graph = $this->graph(['checklists.submit'], 'sync-checklist-');
        DB::table('flight_sessions')->where('flight_session_id', $graph['flight_id'])
            ->update(['flight_status' => 'planned']);

        $response = $this->sync($graph, [[
            'client_id' => 'checklist-client-1',
            'entity' => 'flight_checklist',
            'operation' => 'create',
            'version' => 1,
            'payload' => [
                'flight_id' => $graph['flight_id'],
                'checklist_type' => 'pre_flight',
                'battery_ok' => true,
                'weather_ok' => true,
                'gps_ok' => true,
                'camera_ok' => true,
                'lidar_depth_ok' => true,
                'storage_ok' => true,
                'overall_status' => 'passed',
                'remarks' => 'Offline readiness check',
            ],
        ]]);

        $response->assertOk()
            ->assertJsonPath('data.applied.0.status', 'applied')
            ->assertJsonPath('data.applied.0.server_version', 2)
            ->assertJsonCount(0, 'data.conflicts');
        $this->assertDatabaseHas('flight_checklists', [
            'flight_session_id' => $graph['flight_id'],
            'overall_status' => 'passed',
        ]);
        $this->assertTrue(
            collect($response->json('data.server_changes'))
                ->contains(fn (array $change): bool => $change['entity'] === 'flight_checklist'),
            json_encode($response->json('data.server_changes'), JSON_THROW_ON_ERROR),
        );
    }

    public function test_it_applies_a_flight_lifecycle_transition(): void
    {
        $graph = $this->graph(['flights.start'], 'sync-flight-');
        DB::table('flight_sessions')->where('flight_session_id', $graph['flight_id'])
            ->update(['flight_status' => 'planned']);
        DB::table('flight_checklists')->insert([
            'checklist_id' => (string) Str::uuid(),
            'flight_session_id' => $graph['flight_id'],
            'checked_by' => $graph['actor_id'],
            'checklist_type' => 'pre_flight',
            'battery_ok' => true,
            'weather_ok' => true,
            'gps_ok' => true,
            'camera_ok' => true,
            'lidar_depth_ok' => true,
            'storage_ok' => true,
            'overall_status' => 'passed',
            'created_at' => now(),
        ]);

        $response = $this->sync($graph, [[
            'client_id' => 'flight-client-1',
            'entity' => 'flight_session',
            'operation' => 'update',
            'version' => 1,
            'payload' => [
                'flight_id' => $graph['flight_id'],
                'status' => 'flying',
                'started_at' => '2026-09-02T08:00:00+08:00',
                'takeoff_location' => ['type' => 'Point', 'coordinates' => [123.30, 9.30]],
            ],
        ]]);

        $response->assertOk()
            ->assertJsonPath('data.applied.0.data.status', 'flying')
            ->assertJsonPath('data.applied.0.server_version', 2);
        $this->assertDatabaseHas('flight_sessions', [
            'flight_session_id' => $graph['flight_id'],
            'flight_status' => 'flying',
            'sync_version' => 2,
        ]);
    }

    public function test_it_applies_media_quality_with_optimistic_versioning(): void
    {
        $graph = $this->graph(['media.quality_review'], 'sync-media-');
        $mediaId = $this->media($graph);

        $response = $this->sync($graph, [[
            'client_id' => 'media-client-1',
            'entity' => 'media',
            'operation' => 'update',
            'version' => 1,
            'payload' => [
                'media_id' => $mediaId,
                'quality_status' => 'acceptable',
                'quality_score' => 92.5,
                'notes' => 'Reviewed offline',
            ],
        ]]);

        $response->assertOk()
            ->assertJsonPath('data.applied.0.server_id', $mediaId)
            ->assertJsonPath('data.applied.0.server_version', 2)
            ->assertJsonPath('data.applied.0.data.quality_status', 'acceptable');

        $stale = $this->sync($graph, [[
            'client_id' => 'media-client-stale',
            'entity' => 'media',
            'operation' => 'update',
            'version' => 1,
            'payload' => ['media_id' => $mediaId, 'quality_status' => 'rejected'],
        ]]);
        $stale->assertOk()
            ->assertJsonPath('data.conflicts.0.code', 'VERSION_MISMATCH')
            ->assertJsonPath('data.conflicts.0.server_version', 2);
    }

    public function test_it_applies_an_offline_ground_truth_record(): void
    {
        $graph = $this->graph(['validation.record_ground_truth'], 'sync-validation-');
        $sessionId = (string) Str::uuid();
        DB::table('validation_sessions')->insert([
            'validation_session_id' => $sessionId,
            'mission_id' => $graph['mission_id'],
            'site_id' => $graph['site_id'],
            'validated_by' => $graph['actor_id'],
            'validation_date' => '2026-09-02',
            'method' => 'ground_survey',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->sync($graph, [[
            'client_id' => 'validation-client-1',
            'entity' => 'validation_record',
            'operation' => 'create',
            'version' => 1,
            'payload' => [
                'validation_session_id' => $sessionId,
                'field_code' => 'FIELD-001',
                'location' => ['type' => 'Point', 'coordinates' => [123.31, 9.31]],
                'height_m' => 4.25,
                'health_status' => 'healthy',
                'is_tree' => true,
                'notes' => 'Recorded offline',
            ],
        ]]);

        $response->assertOk()
            ->assertJsonPath('data.applied.0.entity', 'validation_record')
            ->assertJsonPath('data.applied.0.data.field_code', 'FIELD-001');
        $this->assertDatabaseHas('ground_truth_tree_records', [
            'validation_session_id' => $sessionId,
            'field_code' => 'FIELD-001',
        ]);
    }

    public function test_it_records_permission_conflicts_without_partial_mutation(): void
    {
        $graph = $this->graph([], 'sync-permission-');
        $mediaId = $this->media($graph);
        $response = $this->sync($graph, [[
            'client_id' => 'permission-client-1',
            'entity' => 'media',
            'operation' => 'update',
            'version' => 1,
            'payload' => ['media_id' => $mediaId, 'quality_status' => 'rejected'],
        ]]);

        $response->assertOk()
            ->assertJsonCount(0, 'data.applied')
            ->assertJsonPath('data.conflicts.0.code', 'PERMISSION_DENIED')
            ->assertJsonPath('data.conflicts.0.details.required_permission', 'media.quality_review');
        $this->assertDatabaseHas('media_assets', [
            'media_asset_id' => $mediaId,
            'quality_status' => 'pending',
        ]);
    }

    public function test_it_rejects_operations_not_supported_by_the_entity(): void
    {
        $graph = $this->graph(['checklists.submit'], 'sync-operation-');
        $response = $this->sync($graph, [[
            'client_id' => 'operation-client-1',
            'entity' => 'flight_checklist',
            'operation' => 'update',
            'version' => 1,
            'payload' => ['flight_id' => $graph['flight_id']],
        ]]);

        $response->assertOk()
            ->assertJsonPath('data.conflicts.0.code', 'UNSUPPORTED_OPERATION')
            ->assertJsonPath('data.conflicts.0.details.allowed_operations.0', 'create');
        $this->assertDatabaseCount('flight_checklists', 0);
    }

    public function test_server_changes_are_permission_filtered_and_include_media_tombstones(): void
    {
        $denied = $this->graph([], 'sync-feed-denied-');
        $this->media($denied);
        $this->sync($denied, [])->assertOk()->assertJsonCount(0, 'data.server_changes');

        $authorized = $this->graph(['media.delete'], 'sync-feed-delete-');
        $mediaId = $this->media($authorized);
        $this->app['auth']->forgetGuards();
        $this->withToken($authorized['token'])->deleteJson('/api/v1/media/'.$mediaId)->assertNoContent();
        $response = $this->sync($authorized, []);

        $response->assertOk();
        $tombstone = collect($response->json('data.server_changes'))
            ->first(fn (array $change): bool => $change['entity'] === 'media' && $change['server_id'] === $mediaId);
        $this->assertIsArray(
            $tombstone,
            json_encode($response->json('data.server_changes'), JSON_THROW_ON_ERROR),
        );
        $this->assertSame('delete', $tombstone['operation']);
        $this->assertSame(2, $tombstone['server_version']);
        $this->assertNull($tombstone['data']);
    }

    /** @param list<string> $permissions @return array<string, string> */
    private function graph(array $permissions, string $prefix): array
    {
        $identity = $this->apiIdentity($permissions, $prefix);
        $lineage = $this->missionLineage($identity['organization_id'], $identity['actor_id'], Str::upper($prefix));
        $device = SyncDevice::query()->create([
            'device_id' => (string) Str::uuid(),
            'user_id' => $identity['actor_id'],
            'platform' => 'android',
            'app_version' => '1.0.0',
            'device_name' => 'Field device',
        ]);

        return [...$identity, ...$lineage, 'device_id' => $device->device_id];
    }

    /** @param array<string, string> $graph */
    private function media(array $graph): string
    {
        $id = (string) Str::uuid();
        DB::table('media_assets')->insert([
            'media_asset_id' => $id,
            'flight_session_id' => $graph['flight_id'],
            'uploaded_by_user_id' => $graph['actor_id'],
            'file_name' => 'sync.jpg',
            'file_type' => 'image',
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => 128,
            'storage_key' => 'private/'.$id.'/sync.jpg',
            'quality_status' => 'pending',
            'processing_status' => 'pending',
            'sync_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, string> $graph @param list<array<string, mixed>> $changes */
    private function sync(array $graph, array $changes)
    {
        return $this->withToken($graph['token'])->postJson('/api/v1/mobile/sync', [
            'device_id' => $graph['device_id'],
            'base_cursor' => app(SyncCursorService::class)->encode(now()->subMinute()->toImmutable()),
            'changes' => $changes,
        ]);
    }
}
