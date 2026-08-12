<?php

namespace Tests\Feature\Rbac;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class UserRoleReplaceTest extends TestCase
{
    use RefreshDatabase;

    // [RBAC-03] The submitted global/current-tenant roles replace the entire prior set atomically.
    public function test_it_replaces_a_users_complete_role_set(): void
    {
        $identity = $this->createIdentityGraph();
        $newRoleIds = [$identity['tenant_role_id'], $identity['global_role_id']];

        $response = $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_rbac_03_success')
            ->putJson('/api/v1/users/'.$identity['target_user_id'].'/roles', [
                'role_ids' => $newRoleIds,
            ]);

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_rbac_03_success')
            ->assertJsonPath('data.user_id', $identity['target_user_id'])
            ->assertJsonPath('data.roles.0.role_id', $identity['global_role_id'])
            ->assertJsonPath('data.roles.1.role_id', $identity['tenant_role_id'])
            ->assertJsonCount(2, 'data.roles')
            ->assertJsonPath('meta.request_id', 'req_rbac_03_success');

        sort($newRoleIds);
        $this->assertSame($newRoleIds, DB::table('user_roles')
            ->where('user_id', $identity['target_user_id'])
            ->orderBy('role_id')
            ->pluck('role_id')
            ->all());

        $audit = AuditLog::query()->where('action', 'role.assign')->sole();
        $this->assertSame($identity['actor_id'], $audit->user_id);
        $this->assertSame($identity['target_user_id'], $audit->record_id);
        $this->assertContains($identity['old_role_id'], $audit->old_values['role_ids']);
        $this->assertContains($identity['foreign_role_id'], $audit->old_values['role_ids']);
        $this->assertSame($newRoleIds, $audit->new_values['role_ids']);
    }

    // [RBAC-03] An explicit empty array removes every role and remains auditable.
    public function test_it_can_replace_roles_with_an_empty_set(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->putJson('/api/v1/users/'.$identity['target_user_id'].'/roles', ['role_ids' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.roles');

        $this->assertDatabaseMissing('user_roles', ['user_id' => $identity['target_user_id']]);
        $this->assertSame([], AuditLog::query()->sole()->new_values['role_ids']);
    }

    // [RBAC-03] Missing and foreign-organization roles fail validation without changing pivots.
    public function test_it_rejects_unavailable_roles(): void
    {
        $identity = $this->createIdentityGraph();
        $before = DB::table('user_roles')
            ->where('user_id', $identity['target_user_id'])
            ->orderBy('role_id')
            ->pluck('role_id')
            ->all();

        $this->withToken($identity['token'])
            ->putJson('/api/v1/users/'.$identity['target_user_id'].'/roles', [
                'role_ids' => [$identity['foreign_role_id']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role_ids'], 'error.details');

        $this->assertSame($before, DB::table('user_roles')
            ->where('user_id', $identity['target_user_id'])
            ->orderBy('role_id')
            ->pluck('role_id')
            ->all());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RBAC-03] Foreign users remain hidden unless organizations.manage is present.
    public function test_it_enforces_target_user_tenant_scope(): void
    {
        $identity = $this->createIdentityGraph();
        $uri = '/api/v1/users/'.$identity['foreign_user_id'].'/roles';

        $this->withToken($identity['token'])
            ->putJson($uri, ['role_ids' => [$identity['global_role_id']]])
            ->assertNotFound();

        Auth::forgetGuards();
        $elevated = $this->createIdentityGraph(grantOrganizationPermission: true);
        $this->withToken($elevated['token'])
            ->putJson('/api/v1/users/'.$elevated['foreign_user_id'].'/roles', [
                'role_ids' => [$elevated['foreign_role_id'], $elevated['global_role_id']],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.roles');
    }

    // [RBAC-03] Audit failure rolls back pivot deletion and insertion.
    public function test_it_rolls_back_replacement_when_audit_persistence_fails(): void
    {
        $identity = $this->createIdentityGraph();
        $before = DB::table('user_roles')
            ->where('user_id', $identity['target_user_id'])
            ->orderBy('role_id')
            ->pluck('role_id')
            ->all();
        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $auditLogger);

        $this->withToken($identity['token'])
            ->putJson('/api/v1/users/'.$identity['target_user_id'].'/roles', [
                'role_ids' => [$identity['tenant_role_id']],
            ])
            ->assertInternalServerError();

        $this->assertSame($before, DB::table('user_roles')
            ->where('user_id', $identity['target_user_id'])
            ->orderBy('role_id')
            ->pluck('role_id')
            ->all());
    }

    // [RBAC-03] Authentication, roles.manage and request validation are mandatory.
    public function test_it_enforces_authentication_permission_and_validation(): void
    {
        $identity = $this->createIdentityGraph();
        $uri = '/api/v1/users/'.$identity['target_user_id'].'/roles';

        $this->putJson($uri, ['role_ids' => []])->assertUnauthorized();

        $withoutPermission = $this->createIdentityGraph(grantRolePermission: false);
        $this->withToken($withoutPermission['token'])
            ->putJson('/api/v1/users/'.$withoutPermission['target_user_id'].'/roles', [
                'role_ids' => [],
            ])
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'roles.manage');

        Auth::forgetGuards();
        $this->withToken($identity['token'])
            ->putJson($uri, ['role_ids' => ['invalid', 'invalid']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role_ids.0', 'role_ids.1'], 'error.details');
    }

    // [RBAC-03] A throttled replacement cannot mutate or append another audit.
    public function test_it_rate_limits_role_replacement(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentityGraph();
        $uri = '/api/v1/users/'.$identity['target_user_id'].'/roles';

        $this->withToken($identity['token'])
            ->putJson($uri, ['role_ids' => [$identity['tenant_role_id']]])
            ->assertOk();
        $this->withToken($identity['token'])
            ->putJson($uri, ['role_ids' => []])
            ->assertTooManyRequests();

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $identity['target_user_id'],
            'role_id' => $identity['tenant_role_id'],
        ]);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /**
     * @return array<string, string>
     */
    private function createIdentityGraph(
        bool $grantRolePermission = true,
        bool $grantOrganizationPermission = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $targetId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        $adminRoleId = (string) Str::uuid();
        $oldRoleId = (string) Str::uuid();
        $tenantRoleId = (string) Str::uuid();
        $globalRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();

        DB::table('organizations')->insert([
            [
                'organization_id' => $organizationId,
                'organization_name' => 'MangroScan Research',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => $foreignOrganizationId,
                'organization_name' => 'Foreign Organization',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $this->insertUser($actorId, $organizationId, 'actor.'.$actorId.'@example.test');
        $this->insertUser($targetId, $organizationId, 'target.'.$targetId.'@example.test');
        $this->insertUser(
            $foreignUserId,
            $foreignOrganizationId,
            'foreign.'.$foreignUserId.'@example.test',
        );

        DB::table('roles')->insert([
            $this->role($adminRoleId, $organizationId, 'Role Administrator', 'role_administrator'),
            $this->role($oldRoleId, $organizationId, 'Old Role', 'old_role'),
            $this->role($tenantRoleId, $organizationId, 'Researcher', 'researcher'),
            $this->role($globalRoleId, null, 'Global Viewer', 'global_viewer'),
            $this->role(
                $foreignRoleId,
                $foreignOrganizationId,
                'Foreign Researcher',
                'foreign_researcher',
            ),
        ]);
        DB::table('user_roles')->insert([
            [
                'user_id' => $actorId,
                'role_id' => $adminRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $targetId,
                'role_id' => $oldRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $targetId,
                'role_id' => $foreignRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $foreignUserId,
                'role_id' => $foreignRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        foreach (array_filter([
            $grantRolePermission ? 'roles.manage' : null,
            $grantOrganizationPermission ? 'organizations.manage' : null,
        ]) as $code) {
            $permissionId = DB::table('permissions')
                ->where('permission_code', $code)
                ->value('permission_id');

            if ($permissionId === null) {
                $permissionId = (string) Str::uuid();
                DB::table('permissions')->insert([
                    'permission_id' => $permissionId,
                    'permission_code' => $code,
                    'permission_name' => $code,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('role_permissions')->insert([
                'role_id' => $adminRoleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'actor_id' => $actorId,
            'target_user_id' => $targetId,
            'foreign_user_id' => $foreignUserId,
            'old_role_id' => $oldRoleId,
            'tenant_role_id' => $tenantRoleId,
            'global_role_id' => $globalRoleId,
            'foreign_role_id' => $foreignRoleId,
            'token' => User::query()
                ->findOrFail($actorId)
                ->createToken('Role replacement test', ['*'], now()->addHour())
                ->plainTextToken,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function role(string $roleId, ?string $organizationId, string $name, string $code): array
    {
        return [
            'role_id' => $roleId,
            'organization_id' => $organizationId,
            'role_name' => $name,
            'role_code' => $code,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function insertUser(string $userId, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'first_name' => 'RBAC',
            'last_name' => 'User',
            'email' => $email,
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
