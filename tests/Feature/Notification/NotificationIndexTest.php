<?php

namespace Tests\Feature\Notification;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationIndexTest extends TestCase
{
    use RefreshDatabase;

    // [NOTIF-01] The current user receives exact durable resources and page metadata.
    public function test_it_lists_only_the_current_users_notifications(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_notif_01')
            ->getJson('/api/v1/notifications?per_page=2&page=1');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_notif_01')
            ->assertJsonPath('meta', [
                'request_id' => 'req_notif_01',
                'page' => 1,
                'per_page' => 2,
                'total' => 3,
                'last_page' => 2,
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.notification_id', $graph['latest_notification_id'])
            ->assertJsonPath('data.0.notification_type', 'report_ready')
            ->assertJsonPath('data.0.is_read', false)
            ->assertJsonPath('data.0.created_at', '2026-08-12T03:00:00+00:00');

        $this->assertSame([
            'notification_id', 'user_id', 'notification_type', 'title',
            'message', 'is_read', 'created_at',
        ], array_keys($response->json('data.0')));
        $this->assertNotContains($graph['same_tenant_notification_id'], $response->json('data.*.notification_id'));
        $this->assertNotContains($graph['foreign_notification_id'], $response->json('data.*.notification_id'));
        $this->assertDatabaseCount('notification_logs', 5);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [NOTIF-01] Unread and normalized type filters compose for the caller only.
    public function test_it_filters_current_user_notifications(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->getJson('/api/v1/notifications?unread_only=true&type=%20REPORT_READY%20')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.notification_id', $graph['latest_notification_id']);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/notifications?unread_only=false&type=mission_completed')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_read', true);
    }

    // [NOTIF-01] Unsafe filters fail through the standard validation envelope.
    public function test_it_validates_notification_filters(): void
    {
        $graph = $this->createGraph();
        $query = http_build_query([
            'unread_only' => 'sometimes',
            'type' => str_repeat('x', 81),
            'page' => 0,
            'per_page' => 101,
        ]);

        $this->withToken($graph['token'])->getJson('/api/v1/notifications?'.$query)
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['unread_only', 'type', 'page', 'per_page'], 'error.details');
    }

    // [NOTIF-01] Authentication and a current/global notifications.read grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();

        $missing = $this->createGraph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->getJson('/api/v1/notifications')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'notifications.read');

        $foreign = $this->createGraph(foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->getJson('/api/v1/notifications')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'notifications.read');
    }

    // [NOTIF-01] Inactive identities cannot inspect notifications.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($graph['token'])->getJson('/api/v1/notifications')->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [NOTIF-01] Notification reads use the shared authenticated request budget.
    public function test_it_rate_limits_notification_lists(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->getJson('/api/v1/notifications')->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/notifications')->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [NOTIF-01] The authoritative durable schema and read-only API DCL are versioned.
    public function test_it_versions_notification_schema_and_read_only_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_064400_create_notification_logs_table.php'));
        $dcl = file_get_contents(database_path('sql/dcl/023_notification_log_grants.sql'));

        $this->assertIsString($migration);
        foreach (['notification_id', 'notification_type', 'title', 'message', 'is_read', 'created_at'] as $field) {
            $this->assertStringContainsString("'{$field}'", $migration);
        }
        $this->assertStringContainsString("['user_id', 'is_read', 'created_at']", $migration);
        $this->assertStringContainsString("['user_id', 'notification_type', 'created_at']", $migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.notification_logs TO mangroscan_api_rw;', $dcl);
        foreach (['INSERT', 'UPDATE', 'DELETE', 'mangroscan_worker', 'mangroscan_report_ro', 'mangroscan_auditor'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    // [NOTIF-01] PostgreSQL enforces durable user lineage.
    public function test_postgresql_rejects_notifications_for_unknown_users(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL foreign-key verification.');
        }

        $this->expectException(QueryException::class);
        DB::table('notification_logs')->insert([
            'notification_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'notification_type' => 'report_ready',
            'title' => 'Ready',
            'message' => 'Your report is ready.',
            'is_read' => false,
            'created_at' => now(),
        ]);
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
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Notification Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Notification Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actorId, $organizationId, $prefix.'reader@example.test');
        $this->user($sameTenantUserId, $organizationId, $prefix.'colleague@example.test');
        $this->user($foreignUserId, $foreignOrganizationId, $prefix.'foreign@example.test');

        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $permissionId = DB::table('permissions')
            ->where('permission_code', 'notifications.read')
            ->value('permission_id') ?? (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRoleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Notification Reader', 'role_code' => $prefix.'notification_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Notification Reader', 'role_code' => $prefix.'foreign_notification_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insertOrIgnore([
            'permission_id' => $permissionId, 'permission_code' => 'notifications.read',
            'permission_name' => 'Read notifications', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($permission || $foreignPermission) {
            $roleId = $foreignPermission ? $foreignRoleId : $localRoleId;
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        }

        $latestNotificationId = (string) Str::uuid();
        $sameTenantNotificationId = (string) Str::uuid();
        $foreignNotificationId = (string) Str::uuid();
        $this->notification((string) Str::uuid(), $actorId, 'processing_failed', false, '2026-08-12T01:00:00+00:00');
        $this->notification((string) Str::uuid(), $actorId, 'mission_completed', true, '2026-08-12T02:00:00+00:00');
        $this->notification($latestNotificationId, $actorId, 'report_ready', false, '2026-08-12T03:00:00+00:00');
        $this->notification($sameTenantNotificationId, $sameTenantUserId, 'report_ready', false, '2026-08-12T04:00:00+00:00');
        $this->notification($foreignNotificationId, $foreignUserId, 'report_ready', false, '2026-08-12T05:00:00+00:00');

        return [
            'actor_id' => $actorId,
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'notification-index')->plainTextToken,
            'latest_notification_id' => $latestNotificationId,
            'same_tenant_notification_id' => $sameTenantNotificationId,
            'foreign_notification_id' => $foreignNotificationId,
        ];
    }

    private function user(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId,
            'first_name' => 'Notification', 'last_name' => 'User', 'email' => $email,
            'password' => Hash::make('password'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function notification(string $id, string $userId, string $type, bool $read, string $createdAt): void
    {
        DB::table('notification_logs')->insert([
            'notification_id' => $id, 'user_id' => $userId,
            'notification_type' => $type, 'title' => Str::headline($type),
            'message' => 'Durable notification: '.$type, 'is_read' => $read,
            'created_at' => $createdAt,
        ]);
    }
}
