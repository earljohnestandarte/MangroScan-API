<?php

namespace Tests\Feature\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationReadTest extends TestCase
{
    use RefreshDatabase;

    // [NOTIF-03] A caller atomically marks one owned notification read.
    public function test_it_marks_a_current_user_notification_as_read(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_notif_03')
            ->postJson('/api/v1/notifications/'.$graph['unread_notification_id'].'/read');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_notif_03')
            ->assertJsonPath('data.notification_id', $graph['unread_notification_id'])
            ->assertJsonPath('data.user_id', $graph['actor_id'])
            ->assertJsonPath('data.notification_type', 'report_ready')
            ->assertJsonPath('data.is_read', true)
            ->assertJsonPath('data.created_at', '2026-08-12T01:00:00+00:00')
            ->assertJsonPath('meta.request_id', 'req_notif_03');

        $this->assertSame([
            'notification_id', 'user_id', 'notification_type', 'title',
            'message', 'is_read', 'created_at',
        ], array_keys($response->json('data')));
        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $graph['unread_notification_id'],
            'is_read' => true,
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [NOTIF-03] A repeated read transition returns a documented conflict.
    public function test_it_rejects_an_already_read_notification(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->postJson('/api/v1/notifications/'.$graph['read_notification_id'].'/read')
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.details.notification_id', $graph['read_notification_id'])
            ->assertJsonPath('error.details.is_read', true);
    }

    // [NOTIF-03] Same-tenant colleagues, foreign tenants and unknown IDs are non-enumerable.
    public function test_it_hides_unowned_and_missing_notifications(): void
    {
        $graph = $this->createGraph();

        foreach ([
            $graph['same_tenant_notification_id'],
            $graph['foreign_notification_id'],
            (string) Str::uuid(),
        ] as $notificationId) {
            $this->withToken($graph['token'])
                ->postJson('/api/v1/notifications/'.$notificationId.'/read')
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }

        $this->postJson('/api/v1/notifications/not-a-uuid/read')->assertNotFound();
        $this->assertDatabaseMissing('notification_logs', [
            'notification_id' => $graph['same_tenant_notification_id'],
            'is_read' => true,
        ]);
    }

    // [NOTIF-03] Authentication and a tenant-valid notifications.read grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $graph = $this->createGraph(prefix: 'auth-');
        $this->postJson('/api/v1/notifications/'.$graph['unread_notification_id'].'/read')->assertUnauthorized();

        $missing = $this->createGraph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])
            ->postJson('/api/v1/notifications/'.$missing['unread_notification_id'].'/read')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'notifications.read');

        $foreign = $this->createGraph(foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])
            ->postJson('/api/v1/notifications/'.$foreign['unread_notification_id'].'/read')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'notifications.read');
    }

    // [NOTIF-03] Inactive identities cannot change read state.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($graph['token'])
            ->postJson('/api/v1/notifications/'.$graph['unread_notification_id'].'/read')
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $graph['unread_notification_id'], 'is_read' => false,
        ]);
    }

    // [NOTIF-03] Read transitions use the shared authenticated request budget.
    public function test_it_rate_limits_notification_read_transitions(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->postJson('/api/v1/notifications/'.$graph['unread_notification_id'].'/read')->assertOk();
        $this->withToken($graph['token'])
            ->postJson('/api/v1/notifications/'.$graph['read_notification_id'].'/read')
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [NOTIF-03] Additive DCL grants API UPDATE without notification creation/deletion.
    public function test_it_versions_least_privilege_notification_update_dcl(): void
    {
        $read = file_get_contents(database_path('sql/dcl/023_notification_log_grants.sql'));
        $write = file_get_contents(database_path('sql/dcl/024_notification_log_write_grants.sql'));

        $this->assertIsString($read);
        $this->assertIsString($write);
        $combined = $read.$write;
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.notification_logs TO mangroscan_api_rw;', $combined);
        $this->assertStringContainsString('GRANT UPDATE ON TABLE app.notification_logs TO mangroscan_api_rw;', $combined);
        foreach (['INSERT', 'DELETE', 'mangroscan_worker', 'mangroscan_report_ro', 'mangroscan_auditor'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined);
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
        $sameTenantUserId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();

        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Read Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Read Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actorId, $organizationId, $prefix.'notification-reader@example.test');
        $this->user($sameTenantUserId, $organizationId, $prefix.'notification-colleague@example.test');
        $this->user($foreignUserId, $foreignOrganizationId, $prefix.'foreign-notification@example.test');

        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRoleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Read Marker', 'role_code' => $prefix.'read_marker', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Read Marker', 'role_code' => $prefix.'foreign_read_marker', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'notifications.read')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore([
            'permission_id' => $permissionId, 'permission_code' => 'notifications.read',
            'permission_name' => 'Read notifications', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($permission || $foreignPermission) {
            $roleId = $foreignPermission ? $foreignRoleId : $localRoleId;
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        }

        $unreadNotificationId = $this->notification($actorId, false, '2026-08-12T01:00:00+00:00');
        $readNotificationId = $this->notification($actorId, true, '2026-08-12T02:00:00+00:00');
        $sameTenantNotificationId = $this->notification($sameTenantUserId, false, '2026-08-12T03:00:00+00:00');
        $foreignNotificationId = $this->notification($foreignUserId, false, '2026-08-12T04:00:00+00:00');

        return [
            'actor_id' => $actorId,
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'notification-read')->plainTextToken,
            'unread_notification_id' => $unreadNotificationId,
            'read_notification_id' => $readNotificationId,
            'same_tenant_notification_id' => $sameTenantNotificationId,
            'foreign_notification_id' => $foreignNotificationId,
        ];
    }

    private function user(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId,
            'first_name' => 'Notification', 'last_name' => 'Reader', 'email' => $email,
            'password' => Hash::make('password'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function notification(string $userId, bool $read, string $createdAt): string
    {
        $id = (string) Str::uuid();
        DB::table('notification_logs')->insert([
            'notification_id' => $id, 'user_id' => $userId,
            'notification_type' => 'report_ready', 'title' => 'Report ready',
            'message' => 'Your report is ready.', 'is_read' => $read,
            'created_at' => $createdAt,
        ]);

        return $id;
    }
}
