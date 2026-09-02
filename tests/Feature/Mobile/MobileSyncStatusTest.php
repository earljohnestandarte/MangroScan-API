<?php

namespace Tests\Feature\Mobile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileSyncStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_registered_device_sync_status(): void
    {
        $graph = $this->createGraph();

        DB::table('sync_devices')->insert([
            'device_id' => $graph['device_id'],
            'user_id' => $graph['user_id'],
            'platform' => 'android',
            'app_version' => '1.2.3',
            'device_name' => 'Field Tablet',
            'last_cursor' => 'cursor-123',
            'last_sync_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/mobile/sync/status?device_id='.$graph['device_id'])
            ->assertOk()
            ->assertJsonPath('data.last_cursor', 'cursor-123')
            ->assertJsonPath('data.pending_notifications', [])
            ->assertJsonPath('meta.request_id', fn ($value) => is_string($value) && str_starts_with($value, 'req_'));
    }

    public function test_it_returns_only_the_users_unread_pending_notifications(): void
    {
        $graph = $this->createGraph();
        DB::table('sync_devices')->insert([
            'device_id' => $graph['device_id'],
            'user_id' => $graph['user_id'],
            'platform' => 'android',
            'app_version' => '1.2.3',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('notification_logs')->insert([
            [
                'notification_id' => (string) Str::uuid(),
                'user_id' => $graph['user_id'],
                'notification_type' => 'sync_ready',
                'title' => 'New field work',
                'message' => 'A mission update is ready.',
                'is_read' => false,
                'created_at' => now(),
            ],
            [
                'notification_id' => (string) Str::uuid(),
                'user_id' => $graph['user_id'],
                'notification_type' => 'already_read',
                'title' => 'Old notice',
                'message' => 'Already handled.',
                'is_read' => true,
                'created_at' => now(),
            ],
        ]);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/mobile/sync/status?device_id='.$graph['device_id'])
            ->assertOk()
            ->assertJsonCount(1, 'data.pending_notifications')
            ->assertJsonPath('data.pending_notifications.0.notification_type', 'sync_ready');
    }

    public function test_it_requires_authentication(): void
    {
        $deviceId = (string) Str::uuid();

        $this->getJson('/api/v1/mobile/sync/status?device_id='.$deviceId)
            ->assertUnauthorized();
    }

    public function test_it_does_not_expose_a_foreign_tenant_device(): void
    {
        $graph = $this->createGraph();

        DB::table('sync_devices')->insert([
            'device_id' => (string) Str::uuid(),
            'user_id' => $graph['foreign_user_id'],
            'platform' => 'android',
            'app_version' => '1.0.0',
            'device_name' => 'Foreign Tablet',
            'last_cursor' => 'foreign-cursor',
            'last_sync_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreignDeviceId = DB::table('sync_devices')
            ->where('user_id', $graph['foreign_user_id'])
            ->value('device_id');

        $this->withToken($graph['token'])
            ->getJson('/api/v1/mobile/sync/status?device_id='.$foreignDeviceId)
            ->assertNotFound();
    }

    /** @return array<string, string> */
    private function createGraph(): array
    {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();

        DB::table('organizations')->insert([
            [
                'organization_id' => $organizationId,
                'organization_name' => 'Sync Status Org',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => $foreignOrganizationId,
                'organization_name' => 'Foreign Sync Status Org',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->insertUser($userId, $organizationId, 'sync-status@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, 'foreign-sync-status@example.test');

        return [
            'user_id' => $userId,
            'foreign_user_id' => $foreignUserId,
            'device_id' => (string) Str::uuid(),
            'token' => User::query()
                ->findOrFail($userId)
                ->createToken('Sync status test', ['*'], now()->addHour())
                ->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id,
            'organization_id' => $organizationId,
            'first_name' => 'Sync',
            'last_name' => 'Status',
            'email' => $email,
            'password' => Hash::make('password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
