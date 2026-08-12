<?php

namespace Tests\Feature\User;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class UserStoreTest extends TestCase
{
    use RefreshDatabase;

    // [USR-02] An authorized manager creates one normalized user with tenant/global roles and safe audits.
    public function test_it_creates_a_managed_user_with_roles_atomically(): void
    {
        $identity = $this->createIdentityGraph();

        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer '.$identity['token'],
                'X-Request-ID' => 'req_usr_02_success',
                'User-Agent' => 'MangroScan User Admin Test',
            ])
            ->postJson('/api/v1/users', [
                'organization_id' => $identity['organization_id'],
                'first_name' => '  New ',
                'last_name' => ' Researcher  ',
                'email' => '  NEW.RESEARCHER@EXAMPLE.TEST ',
                'position_title' => ' Environmental Scientist ',
                'roles' => [$identity['tenant_role_id'], $identity['global_role_id']],
            ]);

        $response
            ->assertCreated()
            ->assertHeader('X-Request-ID', 'req_usr_02_success')
            ->assertJsonPath('data.organization_id', $identity['organization_id'])
            ->assertJsonPath('data.first_name', 'New')
            ->assertJsonPath('data.last_name', 'Researcher')
            ->assertJsonPath('data.email', 'new.researcher@example.test')
            ->assertJsonPath('data.position_title', 'Environmental Scientist')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('meta.request_id', 'req_usr_02_success');

        $userId = $response->json('data.user_id');
        $user = User::query()->findOrFail($userId);
        $this->assertFalse(Hash::check('correct-password', $user->password));
        $this->assertFalse(Hash::check('new.researcher@example.test', $user->password));
        $expectedRoleIds = [$identity['global_role_id'], $identity['tenant_role_id']];
        sort($expectedRoleIds);
        $this->assertSame(
            $expectedRoleIds,
            $user->roles()->orderBy('roles.role_id')->pluck('roles.role_id')->all(),
        );

        $this->assertSame(['role.assign', 'user.create'], AuditLog::query()
            ->orderBy('action')
            ->pluck('action')
            ->all());
        foreach (AuditLog::query()->get() as $audit) {
            $this->assertSame($identity['actor_id'], $audit->user_id);
            $this->assertSame('req_usr_02_success', $audit->request_id);
            $this->assertStringNotContainsString('correct-password', $audit->toJson());
            $this->assertStringNotContainsString($user->password, $audit->toJson());
        }
    }

    // [USR-02] Foreign organization creation requires organizations.manage.
    public function test_it_denies_unauthorized_cross_organization_creation(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->postJson('/api/v1/users', $this->validPayload($identity, foreign: true))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [USR-02] Elevated managers may create only with roles valid for the selected organization.
    public function test_it_allows_authorized_cross_organization_creation(): void
    {
        $identity = $this->createIdentityGraph(grantOrganizationPermission: true);

        $response = $this->withToken($identity['token'])
            ->postJson('/api/v1/users', $this->validPayload($identity, foreign: true))
            ->assertCreated()
            ->assertJsonPath('data.organization_id', $identity['foreign_organization_id']);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $response->json('data.user_id'),
            'role_id' => $identity['foreign_role_id'],
        ]);
    }

    // [USR-02] A role from another organization fails validation and leaves no partial user.
    public function test_it_rejects_roles_outside_the_target_organization(): void
    {
        $identity = $this->createIdentityGraph();
        $payload = $this->validPayload($identity);
        $payload['roles'] = [$identity['foreign_role_id']];

        $this->withToken($identity['token'])
            ->postJson('/api/v1/users', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['roles'], 'error.details');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [USR-02] Email uniqueness and the full request contract are validated after normalization.
    public function test_it_validates_user_creation_input(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->postJson('/api/v1/users', [
                'organization_id' => 'invalid',
                'first_name' => '',
                'email' => ' '.Str::upper($identity['actor_email']).' ',
                'roles' => ['invalid', 'invalid'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                ['organization_id', 'first_name', 'last_name', 'email', 'roles.0', 'roles.1'],
                'error.details',
            );

        $this->assertDatabaseCount('users', 1);
    }

    // [USR-02] Mandatory audit failure rolls back the user and pivot writes.
    public function test_it_rolls_back_creation_when_audit_persistence_fails(): void
    {
        $identity = $this->createIdentityGraph();
        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $auditLogger);

        $this->withToken($identity['token'])
            ->postJson('/api/v1/users', $this->validPayload($identity))
            ->assertInternalServerError();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_roles', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [USR-02] Authentication and users.manage remain mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $identity = $this->createIdentityGraph();

        $this->postJson('/api/v1/users', $this->validPayload($identity))
            ->assertUnauthorized();

        $withoutPermission = $this->createIdentityGraph(grantUserPermission: false);
        $this->withToken($withoutPermission['token'])
            ->postJson('/api/v1/users', $this->validPayload($withoutPermission))
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'users.manage');
    }

    // [USR-02] A throttled second request cannot create or audit another user.
    public function test_it_rate_limits_user_creation(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->postJson('/api/v1/users', $this->validPayload($identity))
            ->assertCreated();

        $payload = $this->validPayload($identity);
        $payload['email'] = 'second@example.test';
        $this->withToken($identity['token'])
            ->postJson('/api/v1/users', $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    /**
     * @param  array<string, string>  $identity
     * @return array<string, mixed>
     */
    private function validPayload(array $identity, bool $foreign = false): array
    {
        return [
            'organization_id' => $foreign
                ? $identity['foreign_organization_id']
                : $identity['organization_id'],
            'first_name' => 'New',
            'last_name' => 'Researcher',
            'email' => 'new.researcher@example.test',
            'position_title' => 'Researcher',
            'roles' => [
                $foreign ? $identity['foreign_role_id'] : $identity['tenant_role_id'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function createIdentityGraph(
        bool $grantUserPermission = true,
        bool $grantOrganizationPermission = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $adminRoleId = (string) Str::uuid();
        $tenantRoleId = (string) Str::uuid();
        $globalRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $actorEmail = 'manager.'.$actorId.'@example.test';

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
        DB::table('users')->insert([
            'user_id' => $actorId,
            'organization_id' => $organizationId,
            'first_name' => 'User',
            'last_name' => 'Manager',
            'email' => $actorEmail,
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('roles')->insert([
            [
                'role_id' => $adminRoleId,
                'organization_id' => $organizationId,
                'role_name' => 'User Administrator',
                'role_code' => 'user_administrator',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'role_id' => $tenantRoleId,
                'organization_id' => $organizationId,
                'role_name' => 'Researcher',
                'role_code' => 'researcher',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'role_id' => $globalRoleId,
                'organization_id' => null,
                'role_name' => 'Global Viewer',
                'role_code' => 'global_viewer',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'role_id' => $foreignRoleId,
                'organization_id' => $foreignOrganizationId,
                'role_name' => 'Foreign Researcher',
                'role_code' => 'foreign_researcher',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $actorId,
            'role_id' => $adminRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (array_filter([
            $grantUserPermission ? 'users.manage' : null,
            $grantOrganizationPermission ? 'organizations.manage' : null,
        ]) as $code) {
            $permissionId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'permission_id' => $permissionId,
                'permission_code' => $code,
                'permission_name' => $code,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('role_permissions')->insert([
                'role_id' => $adminRoleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'organization_id' => $organizationId,
            'foreign_organization_id' => $foreignOrganizationId,
            'actor_id' => $actorId,
            'actor_email' => $actorEmail,
            'tenant_role_id' => $tenantRoleId,
            'global_role_id' => $globalRoleId,
            'foreign_role_id' => $foreignRoleId,
            'token' => User::query()
                ->findOrFail($actorId)
                ->createToken('User creation test', ['*'], now()->addHour())
                ->plainTextToken,
        ];
    }
}
