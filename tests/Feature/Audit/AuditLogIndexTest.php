<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditLogIndexTest extends TestCase
{
    use RefreshDatabase;

    // [AUD-01] Tenant auditors receive stable pages of exact immutable resources.
    public function test_it_lists_only_current_organization_audit_events(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_aud_01')
            ->getJson('/api/v1/audit-logs?per_page=2&page=1');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_aud_01')
            ->assertJsonPath('meta', [
                'request_id' => 'req_aud_01',
                'page' => 1,
                'per_page' => 2,
                'total' => 3,
                'last_page' => 2,
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.audit_log_id', $graph['latest_local_audit_id'])
            ->assertJsonPath('data.0.old_values.status', 'planned')
            ->assertJsonPath('data.0.new_values.status', 'approved')
            ->assertJsonPath('data.0.created_at', '2026-08-12T03:00:00+00:00');

        $this->assertSame([
            'audit_log_id', 'user_id', 'action', 'table_name', 'record_id',
            'old_values', 'new_values', 'ip_address', 'user_agent', 'request_id',
            'created_at',
        ], array_keys($response->json('data.0')));
        $this->assertNotContains($graph['foreign_audit_id'], $response->json('data.*.audit_log_id'));
        $this->assertNotContains($graph['system_audit_id'], $response->json('data.*.audit_log_id'));
        $this->assertDatabaseCount('audit_logs', 5);
    }

    // [AUD-01] Every documented filter composes after safe normalization.
    public function test_it_filters_the_audit_trail(): void
    {
        $graph = $this->createGraph();
        $query = http_build_query([
            'user_id' => strtoupper($graph['local_user_id']),
            'action' => ' MISSION.APPROVAL ',
            'table_name' => ' SURVEY_MISSIONS ',
            'record_id' => strtoupper($graph['record_id']),
            'from' => '2026-08-12T02:30:00+00:00',
            'to' => '2026-08-12T03:30:00+00:00',
        ]);

        $this->withToken($graph['token'])->getJson('/api/v1/audit-logs?'.$query)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.audit_log_id', $graph['latest_local_audit_id']);
    }

    // [AUD-01] Deleted actors remain searchable as historical evidence.
    public function test_it_preserves_visibility_for_soft_deleted_tenant_actors(): void
    {
        $graph = $this->createGraph();
        DB::table('users')->where('user_id', $graph['local_user_id'])->update(['deleted_at' => now()]);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/audit-logs?user_id='.$graph['local_user_id'])
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    // [AUD-01] Foreign and missing actor filters are non-enumerable without global authority.
    public function test_it_hides_unavailable_actor_filters(): void
    {
        $graph = $this->createGraph();

        foreach ([$graph['foreign_user_id'], (string) Str::uuid()] as $userId) {
            $this->withToken($graph['token'])->getJson('/api/v1/audit-logs?user_id='.$userId)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    // [AUD-01] Explicit organization administration authority enables global audit review.
    public function test_organization_administrators_can_read_foreign_and_system_events(): void
    {
        $graph = $this->createGraph(global: true);

        $this->withToken($graph['token'])->getJson('/api/v1/audit-logs')
            ->assertOk()->assertJsonPath('meta.total', 5);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/audit-logs?user_id='.$graph['foreign_user_id'])
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.audit_log_id', $graph['foreign_audit_id']);
    }

    // [AUD-01] Unsafe query values fail through the standard validation envelope.
    public function test_it_validates_audit_filters(): void
    {
        $graph = $this->createGraph();
        $query = http_build_query([
            'user_id' => 'invalid',
            'action' => ['invalid'],
            'table_name' => str_repeat('x', 101),
            'record_id' => 'invalid',
            'from' => 'yesterday',
            'to' => 'tomorrow',
            'page' => 0,
            'per_page' => 101,
        ]);

        $this->withToken($graph['token'])->getJson('/api/v1/audit-logs?'.$query)
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(
                ['user_id', 'action', 'table_name', 'record_id', 'from', 'to', 'page', 'per_page'],
                'error.details',
            );

        $this->withToken($graph['token'])->getJson(
            '/api/v1/audit-logs?from=2026-08-13T00:00:00%2B00:00&to=2026-08-12T00:00:00%2B00:00',
        )->assertUnprocessable()->assertJsonValidationErrors(['to'], 'error.details');
    }

    // [AUD-01] Authentication and a tenant-valid audit.read grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $this->getJson('/api/v1/audit-logs')->assertUnauthorized();

        $missing = $this->createGraph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->getJson('/api/v1/audit-logs')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'audit.read');

        $foreign = $this->createGraph(foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->getJson('/api/v1/audit-logs')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'audit.read');
    }

    // [AUD-01] Inactive identities cannot inspect audit evidence.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->createGraph(prefix: 'inactive-');
        $token = $graph['token'];
        $userId = DB::table('personal_access_tokens')->value('tokenable_id');
        DB::table('users')->where('user_id', $userId)->update(['status' => 'inactive']);

        $this->withToken($token)->getJson('/api/v1/audit-logs')->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [AUD-01] Audit reads use the shared authenticated request budget.
    public function test_it_rate_limits_audit_searches(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->getJson('/api/v1/audit-logs')->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/audit-logs')->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [AUD-01] Existing append-only DCL supplies read access without mutation authority.
    public function test_it_reuses_immutable_audit_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/002_identity_and_audit_grants.sql'));

        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT, INSERT ON TABLE app.audit_logs TO mangroscan_api_rw;', $dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.audit_logs TO mangroscan_auditor;', $dcl);
        $this->assertStringContainsString('REVOKE UPDATE, DELETE, TRUNCATE ON TABLE app.audit_logs', $dcl);
    }

    /** @return array<string, string> */
    private function createGraph(
        bool $permission = true,
        bool $foreignPermission = false,
        bool $global = false,
        string $prefix = '',
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $localUserId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();

        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Audit Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Audit Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actorId, $organizationId, $prefix.'auditor@example.test');
        $this->user($localUserId, $organizationId, $prefix.'local@example.test');
        $this->user($foreignUserId, $foreignOrganizationId, $prefix.'foreign@example.test');

        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRoleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Auditor', 'role_code' => $prefix.'auditor', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Auditor', 'role_code' => $prefix.'foreign_auditor', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $auditPermissionId = $this->permission('audit.read');

        if ($permission || $foreignPermission) {
            DB::table('role_permissions')->insert([
                'role_id' => $foreignPermission ? $foreignRoleId : $localRoleId,
                'permission_id' => $auditPermissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('user_roles')->insert([
                'user_id' => $actorId,
                'role_id' => $foreignPermission ? $foreignRoleId : $localRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($global) {
            $organizationPermissionId = $this->permission('organizations.manage');
            DB::table('role_permissions')->insert([
                'role_id' => $localRoleId,
                'permission_id' => $organizationPermissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $recordId = (string) Str::uuid();
        $latestLocalAuditId = (string) Str::uuid();
        $foreignAuditId = (string) Str::uuid();
        $systemAuditId = (string) Str::uuid();
        $this->audit((string) Str::uuid(), $actorId, 'auth.login', 'users', $actorId, '2026-08-12 01:00:00');
        $this->audit((string) Str::uuid(), $localUserId, 'mission.create', 'survey_missions', $recordId, '2026-08-12 02:00:00');
        $this->audit($latestLocalAuditId, $localUserId, 'mission.approval', 'survey_missions', $recordId, '2026-08-12 03:00:00', true);
        $this->audit($foreignAuditId, $foreignUserId, 'mission.create', 'survey_missions', (string) Str::uuid(), '2026-08-12 04:00:00');
        $this->audit($systemAuditId, null, 'system.health', 'system', null, '2026-08-12 05:00:00');

        return [
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'audit-index')->plainTextToken,
            'local_user_id' => $localUserId,
            'foreign_user_id' => $foreignUserId,
            'record_id' => $recordId,
            'latest_local_audit_id' => $latestLocalAuditId,
            'foreign_audit_id' => $foreignAuditId,
            'system_audit_id' => $systemAuditId,
        ];
    }

    private function user(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId,
            'first_name' => 'Audit', 'last_name' => 'User', 'email' => $email,
            'password' => Hash::make('password'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function permission(string $code): string
    {
        $existing = DB::table('permissions')->where('permission_code', $code)->value('permission_id');

        if (is_string($existing)) {
            return $existing;
        }

        $id = (string) Str::uuid();
        DB::table('permissions')->insert([
            'permission_id' => $id, 'permission_code' => $code,
            'permission_name' => $code, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function audit(
        string $id,
        ?string $userId,
        string $action,
        string $table,
        ?string $recordId,
        string $createdAt,
        bool $snapshots = false,
    ): void {
        DB::table('audit_logs')->insert([
            'audit_log_id' => $id, 'user_id' => $userId, 'action' => $action,
            'table_name' => $table, 'record_id' => $recordId,
            'old_values' => $snapshots ? json_encode(['status' => 'planned'], JSON_THROW_ON_ERROR) : null,
            'new_values' => $snapshots ? json_encode(['status' => 'approved'], JSON_THROW_ON_ERROR) : null,
            'ip_address' => '127.0.0.1', 'user_agent' => 'MangroScan audit test',
            'request_id' => 'req_'.$id, 'created_at' => $createdAt,
        ]);
    }
}
