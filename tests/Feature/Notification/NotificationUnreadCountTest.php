<?php

namespace Tests\Feature\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationUnreadCountTest extends TestCase
{
    use RefreshDatabase;

    // [NOTIF-02] The badge count includes only the current user's unread rows.
    public function test_it_counts_only_the_current_users_unread_notifications(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_notif_02')
            ->getJson('/api/v1/notifications/unread-count');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_notif_02')
            ->assertExactJson([
                'data' => ['unread_count' => 2],
                'meta' => ['request_id' => 'req_notif_02'],
            ]);
        $this->assertIsInt($response->json('data.unread_count'));
        $this->assertDatabaseCount('notification_logs', 5);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [NOTIF-02] A caller with no unread notifications receives integer zero.
    public function test_it_returns_zero_when_every_current_user_notification_is_read(): void
    {
        $graph = $this->createGraph();
        DB::table('notification_logs')->where('user_id', $graph['actor_id'])->update(['is_read' => true]);

        $this->withToken($graph['token'])->getJson('/api/v1/notifications/unread-count')
            ->assertOk()->assertJsonPath('data.unread_count', 0);
    }

    // [NOTIF-02] Authentication and a tenant-valid notifications.read grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();

        $missing = $this->createGraph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->getJson('/api/v1/notifications/unread-count')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'notifications.read');

        $foreign = $this->createGraph(foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->getJson('/api/v1/notifications/unread-count')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'notifications.read');
    }

    // [NOTIF-02] Inactive identities cannot read badge counts.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($graph['token'])->getJson('/api/v1/notifications/unread-count')->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [NOTIF-02] Badge polling uses the shared authenticated request budget.
    public function test_it_rate_limits_unread_count_requests(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->getJson('/api/v1/notifications/unread-count')->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/notifications/unread-count')->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [NOTIF-02] The existing notification DCL remains sufficient and read-only.
    public function test_it_reuses_notification_read_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/023_notification_log_grants.sql'));

        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.notification_logs TO mangroscan_api_rw;', $dcl);
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $mutation) {
            $this->assertStringNotContainsString($mutation, $dcl);
        }
    }

    /** @return array{actor_id: string, token: string} */
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
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Badge Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Badge Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actorId, $organizationId, $prefix.'badge-reader@example.test');
        $this->user($sameTenantUserId, $organizationId, $prefix.'badge-colleague@example.test');
        $this->user($foreignUserId, $foreignOrganizationId, $prefix.'foreign-badge@example.test');

        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRoleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Badge Reader', 'role_code' => $prefix.'badge_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Badge Reader', 'role_code' => $prefix.'foreign_badge_reader', 'created_at' => now(), 'updated_at' => now()],
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

        $this->notification($actorId, false);
        $this->notification($actorId, false);
        $this->notification($actorId, true);
        $this->notification($sameTenantUserId, false);
        $this->notification($foreignUserId, false);

        return [
            'actor_id' => $actorId,
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'unread-count')->plainTextToken,
        ];
    }

    private function user(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId,
            'first_name' => 'Badge', 'last_name' => 'User', 'email' => $email,
            'password' => Hash::make('password'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function notification(string $userId, bool $read): void
    {
        DB::table('notification_logs')->insert([
            'notification_id' => (string) Str::uuid(), 'user_id' => $userId,
            'notification_type' => 'report_ready', 'title' => 'Report ready',
            'message' => 'Your report is ready.', 'is_read' => $read,
            'created_at' => now(),
        ]);
    }
}
