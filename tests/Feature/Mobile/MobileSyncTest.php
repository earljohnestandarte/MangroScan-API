<?php

namespace Tests\Feature\Mobile;

use App\Models\SyncDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileSyncTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    private function device(User $user): SyncDevice
    {
        return SyncDevice::query()->create([
            'device_id' => (string) Str::uuid(),
            'user_id' => $user->user_id,
            'platform' => 'android',
            'app_version' => '1.0.0',
            'device_name' => 'Test Device',
        ]);
    }

    private function cursor(): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'boundary' => now()->subMinute()->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    private function payload(): array
    {
        return [
            'device_id' => '',
            'base_cursor' => $this->cursor(),
            'changes' => [
                [
                    'client_id' => 'test-client-001',
                    'entity' => 'flight_session',
                    'operation' => 'upsert',
                    'version' => 1,
                    'payload' => [
                        'flight_code' => 'SYNC-TEST-001',
                    ],
                ],
            ],
        ];
    }

    public function test_sync_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/mobile/sync', $this->payload());

        $response->assertUnauthorized();
    }

    public function test_sync_rejects_unregistered_device(): void
    {
        $user = $this->user();

        $payload = $this->payload();
        $payload['device_id'] = (string) Str::uuid();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mobile/sync', $payload);

        $response->assertStatus(422);
    }

    public function test_sync_records_conflict_and_advances_cursor(): void
    {
        $user = $this->user();
        $device = $this->device($user);

        $payload = $this->payload();
        $payload['device_id'] = $device->device_id;

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mobile/sync', $payload);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'applied',
                    'conflicts',
                    'server_changes',
                ],
                'meta' => [
                    'cursor',
                    'request_id',
                ],
            ]);

        $this->assertDatabaseHas('sync_change_log', [
            'device_id' => $device->device_id,
            'client_id' => 'test-client-001',
            'result_status' => 'conflict',
        ]);

        $this->assertDatabaseHas('sync_conflicts', [
            'device_id' => $device->device_id,
            'client_id' => 'test-client-001',
            'conflict_code' => 'VALIDATION_FAILED',
        ]);

        $this->assertNotNull(
            SyncDevice::query()
                ->find($device->device_id)
                ->last_cursor
        );
    }

    public function test_sync_is_idempotent_for_same_client_id_and_payload(): void
    {
        $user = $this->user();
        $device = $this->device($user);

        $payload = $this->payload();
        $payload['device_id'] = $device->device_id;

        $first = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mobile/sync', $payload)
            ->assertOk();

        $second = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mobile/sync', $payload)
            ->assertOk();

        $this->assertEquals($first->json('data'), $second->json('data'));
        $this->assertSame($first->json('meta.cursor'), $second->json('meta.cursor'));
        $this->assertDatabaseCount('sync_requests', 1);
        $this->assertDatabaseCount('sync_change_log', 1);
        $this->assertDatabaseCount('sync_conflicts', 1);
    }

    public function test_sync_rejects_same_client_id_with_different_payload(): void
    {
        $user = $this->user();
        $device = $this->device($user);

        $payload = $this->payload();
        $payload['device_id'] = $device->device_id;

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mobile/sync', $payload)
            ->assertOk();

        $payload['changes'][0]['payload']['flight_code'] = 'DIFFERENT';

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mobile/sync', $payload)
            ->assertStatus(422);
    }

    public function test_sync_rejects_invalid_cursor(): void
    {
        $user = $this->user();
        $device = $this->device($user);

        $payload = $this->payload();
        $payload['device_id'] = $device->device_id;
        $payload['base_cursor'] = 'invalid-cursor';

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mobile/sync', $payload)
            ->assertStatus(422);
    }
}
