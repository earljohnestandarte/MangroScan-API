<?php

namespace Tests\Feature\User;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserShowTest extends TestCase
{
    use RefreshDatabase;

    // [USR-03] User detail returns safe fields plus global/current-tenant roles only.
    public function test_it_returns_a_scoped_user_with_roles(): void
    {
        $identity = $this->createIdentityGraph();

        $response = $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_usr_03_success')
            ->getJson('/api/v1/users/'.$identity['target_user_id']);

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_usr_03_success')
            ->assertJsonPath('data.user.user_id', $identity['target_user_id'])
            ->assertJsonPath('data.user.organization_id', $identity['organization_id'])
            ->assertJsonPath('data.user.position_title', 'Researcher')
            ->assertJsonPath('data.roles.0.role_id', $identity['global_role_id'])
            ->assertJsonPath('data.roles.1.role_id', $identity['tenant_role_id'])
            ->assertJsonCount(2, 'data.roles')
            ->assertJsonPath('meta.request_id', 'req_usr_03_success');

        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [USR-03] Foreign users are hidden without organizations.manage.
    public function test_it_hides_a_foreign_organization_user(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->getJson('/api/v1/users/'.$identity['foreign_user_id'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // [USR-03] Explicit organizations.manage allows cross-organization detail.
    public function test_it_allows_authorized_cross_organization_detail(): void
    {
        $identity = $this->createIdentityGraph(grantOrganizationPermission: true);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/users/'.$identity['foreign_user_id'])
            ->assertOk()
            ->assertJsonPath('data.user.user_id', $identity['foreign_user_id'])
            ->assertJsonPath('data.user.organization_id', $identity['foreign_organization_id']);
    }

    // [USR-03] Missing, soft-deleted and malformed UUIDs use the standard 404.
    public function test_it_returns_not_found_for_unavailable_users(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->getJson('/api/v1/users/'.Str::uuid())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
        $this->withToken($identity['token'])
            ->getJson('/api/v1/users/'.$identity['deleted_user_id'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
        $this->withToken($identity['token'])
            ->getJson('/api/v1/users/not-a-uuid')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // [USR-03] Authentication and users.manage are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $identity = $this->createIdentityGraph();

        $this->getJson('/api/v1/users/'.$identity['target_user_id'])
            ->assertUnauthorized();

        $withoutPermission = $this->createIdentityGraph(grantUserPermission: false);
        $this->withToken($withoutPermission['token'])
            ->getJson('/api/v1/users/'.$withoutPermission['target_user_id'])
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'users.manage');
    }

    // [USR-03] Detail reads consume the authenticated request budget.
    public function test_it_rate_limits_user_detail_requests(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentityGraph();
        $uri = '/api/v1/users/'.$identity['target_user_id'];

        $this->withToken($identity['token'])->getJson($uri)->assertOk();
        $this->withToken($identity['token'])
            ->getJson($uri)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');
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
        $targetId = (string) Str::uuid();
        $deletedId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        $adminRoleId = (string) Str::uuid();
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
        $this->insertUser($actorId, $organizationId, 'Manager', 'manager.'.$actorId.'@example.test');
        $this->insertUser($targetId, $organizationId, 'Researcher', 'researcher.'.$targetId.'@example.test');
        $this->insertUser(
            $deletedId,
            $organizationId,
            'Deleted',
            'deleted.'.$deletedId.'@example.test',
            deletedAt: now(),
        );
        $this->insertUser(
            $foreignUserId,
            $foreignOrganizationId,
            'Foreign',
            'foreign.'.$foreignUserId.'@example.test',
        );

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
                'role_name' => 'Foreign Administrator',
                'role_code' => 'foreign_administrator',
                'created_at' => now(),
                'updated_at' => now(),
            ],
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
                'role_id' => $globalRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $targetId,
                'role_id' => $tenantRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $targetId,
                'role_id' => $foreignRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
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
            'target_user_id' => $targetId,
            'deleted_user_id' => $deletedId,
            'foreign_user_id' => $foreignUserId,
            'tenant_role_id' => $tenantRoleId,
            'global_role_id' => $globalRoleId,
            'token' => User::query()
                ->findOrFail($actorId)
                ->createToken('User detail test', ['*'], now()->addHour())
                ->plainTextToken,
        ];
    }

    private function insertUser(
        string $userId,
        string $organizationId,
        string $firstName,
        string $email,
        ?DateTimeInterface $deletedAt = null,
    ): void {
        DB::table('users')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'first_name' => $firstName,
            'last_name' => 'User',
            'position_title' => 'Researcher',
            'email' => $email,
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deletedAt,
        ]);
    }
}
