<?php

namespace Tests\Feature\User;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserIndexTest extends TestCase
{
    use RefreshDatabase;

    // [USR-01] The default scope is the caller's organization with exact pagination metadata.
    public function test_it_lists_only_current_organization_users_with_safe_fields(): void
    {
        $identity = $this->createIdentityGraph();

        $response = $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_usr_01_success')
            ->getJson('/api/v1/users?per_page=2&page=1');

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_usr_01_success')
            ->assertJsonPath('meta', [
                'request_id' => 'req_usr_01_success',
                'page' => 1,
                'per_page' => 2,
                'total' => 3,
                'last_page' => 2,
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.user_id', $identity['alice_user_id'])
            ->assertJsonPath('data.1.user_id', $identity['bob_user_id']);

        foreach ($response->json('data') as $user) {
            $this->assertSame([
                'user_id',
                'organization_id',
                'first_name',
                'middle_name',
                'last_name',
                'email',
                'is_active',
                'created_at',
                'updated_at',
            ], array_keys($user));
            $this->assertSame($identity['organization_id'], $user['organization_id']);
            $this->assertArrayNotHasKey('password', $user);
        }

        $this->assertFalse($response->json('data.1.is_active'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [USR-01] Search, active and role filters compose without accepting foreign role assignments.
    public function test_it_applies_tenant_scoped_filters(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->getJson('/api/v1/users?role=RESEARCHER&active=true&search=ALICE')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $identity['alice_user_id']);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/users?role=foreign_administrator')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // [USR-01] Cross-tenant org_id is denied without organizations.manage.
    public function test_it_denies_an_unauthorized_cross_organization_query(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_usr_01_cross_tenant')
            ->getJson('/api/v1/users?org_id='.$identity['foreign_organization_id'])
            ->assertForbidden()
            ->assertHeader('X-Request-ID', 'req_usr_01_cross_tenant')
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.request_id', 'req_usr_01_cross_tenant');
    }

    // [USR-01] Explicit organizations.manage permits a selected foreign organization only.
    public function test_it_allows_an_authorized_cross_organization_query(): void
    {
        $identity = $this->createIdentityGraph(grantOrganizationPermission: true);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/users?org_id='.$identity['foreign_organization_id'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $identity['foreign_user_id'])
            ->assertJsonPath('data.0.organization_id', $identity['foreign_organization_id']);
    }

    // [USR-01] Elevated callers receive the standard 404 for an unknown organization.
    public function test_it_rejects_an_unknown_cross_organization_scope(): void
    {
        $identity = $this->createIdentityGraph(grantOrganizationPermission: true);

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_usr_01_not_found')
            ->getJson('/api/v1/users?org_id='.Str::uuid())
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'The requested resource was not found.',
                    'details' => [],
                    'request_id' => 'req_usr_01_not_found',
                ],
            ]);
    }

    // [USR-01] Query values are validated before any scoped lookup runs.
    public function test_it_validates_list_filters(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_usr_01_validation')
            ->getJson('/api/v1/users?org_id=invalid&active=maybe&page=0&per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.request_id', 'req_usr_01_validation')
            ->assertJsonValidationErrors(
                ['org_id', 'active', 'page', 'per_page'],
                'error.details',
            );
    }

    // [USR-01] Authentication and users.manage are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/users')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $identity = $this->createIdentityGraph(grantUserPermission: false);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/users')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.details.required_permission', 'users.manage');
    }

    // [USR-01] Paginated user reads consume the authenticated request budget.
    public function test_it_rate_limits_user_list_requests(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])->getJson('/api/v1/users')->assertOk();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_usr_01_throttled')
            ->getJson('/api/v1/users')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_usr_01_throttled');
    }

    /**
     * @return array{
     *     organization_id: string,
     *     foreign_organization_id: string,
     *     alice_user_id: string,
     *     bob_user_id: string,
     *     foreign_user_id: string,
     *     token: string
     * }
     */
    private function createIdentityGraph(
        bool $grantUserPermission = true,
        bool $grantOrganizationPermission = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $aliceId = (string) Str::uuid();
        $bobId = (string) Str::uuid();
        $deletedId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        $adminRoleId = (string) Str::uuid();
        $researcherRoleId = (string) Str::uuid();
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

        $this->insertUser($actorId, $organizationId, 'User', 'Manager', 'manager@example.test');
        $this->insertUser($aliceId, $organizationId, 'Alice', 'Able', 'alice@example.test');
        $this->insertUser($bobId, $organizationId, 'Bob', 'Baker', 'bob@example.test', 'inactive');
        $this->insertUser(
            $deletedId,
            $organizationId,
            'Deleted',
            'User',
            'deleted@example.test',
            deletedAt: now(),
        );
        $this->insertUser(
            $foreignUserId,
            $foreignOrganizationId,
            'Fiona',
            'Foreign',
            'foreign@example.test',
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
                'role_id' => $researcherRoleId,
                'organization_id' => $organizationId,
                'role_name' => 'Researcher',
                'role_code' => 'researcher',
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
                'user_id' => $aliceId,
                'role_id' => $researcherRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $aliceId,
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
            'alice_user_id' => $aliceId,
            'bob_user_id' => $bobId,
            'foreign_user_id' => $foreignUserId,
            'token' => User::query()
                ->findOrFail($actorId)
                ->createToken('User list test', ['*'], now()->addHour())
                ->plainTextToken,
        ];
    }

    private function insertUser(
        string $userId,
        string $organizationId,
        string $firstName,
        string $lastName,
        string $email,
        string $status = 'active',
        ?DateTimeInterface $deletedAt = null,
    ): void {
        DB::table('users')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => Hash::make('correct-password'),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deletedAt,
        ]);
    }
}
