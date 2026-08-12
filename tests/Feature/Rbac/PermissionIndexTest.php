<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PermissionIndexTest extends TestCase
{
    use RefreshDatabase;

    // [RBAC-02] Authorized callers receive the sorted global permission catalog.
    public function test_it_lists_the_permission_catalog(): void
    {
        $identity = $this->createIdentityGraph(grantLocalPermission: true);

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_rbac_02_success')
            ->getJson('/api/v1/permissions')
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_rbac_02_success')
            ->assertExactJson([
                'data' => [
                    [
                        'permission_id' => $identity['permissions']['permissions.manage'],
                        'permission_code' => 'permissions.manage',
                        'permission_name' => 'Manage permissions',
                        'description' => 'View and assign the permission catalog',
                    ],
                    [
                        'permission_id' => $identity['permissions']['roles.manage'],
                        'permission_code' => 'roles.manage',
                        'permission_name' => 'Manage roles',
                        'description' => null,
                    ],
                    [
                        'permission_id' => $identity['permissions']['users.manage'],
                        'permission_code' => 'users.manage',
                        'permission_name' => 'Manage users',
                        'description' => null,
                    ],
                ],
                'meta' => [
                    'request_id' => 'req_rbac_02_success',
                ],
            ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [RBAC-02] Authentication and permissions.manage are both mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/permissions')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_rbac_02_forbidden')
            ->getJson('/api/v1/permissions')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.details.required_permission', 'permissions.manage')
            ->assertJsonPath('error.request_id', 'req_rbac_02_forbidden');
    }

    // [RBAC-02] Foreign-tenant permissions cannot authorize a global catalog read.
    public function test_foreign_organization_permission_does_not_authorize_the_request(): void
    {
        $identity = $this->createIdentityGraph(grantForeignPermission: true);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/permissions')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // [RBAC-02] Inactive users are rejected before permission evaluation.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $identity = $this->createIdentityGraph(grantLocalPermission: true);
        DB::table('users')->where('user_id', $identity['user_id'])->update(['status' => 'inactive']);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/permissions')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [RBAC-02] Permission catalog reads use the shared authenticated limit.
    public function test_it_rate_limits_permission_catalog_requests(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentityGraph(grantLocalPermission: true);

        $this->withToken($identity['token'])->getJson('/api/v1/permissions')->assertOk();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_rbac_02_throttled')
            ->getJson('/api/v1/permissions')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_rbac_02_throttled');
    }

    /**
     * @return array{
     *     user_id: string,
     *     token: string,
     *     permissions: array<string, string>
     * }
     */
    private function createIdentityGraph(
        bool $grantLocalPermission = false,
        bool $grantForeignPermission = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $localRoleId = (string) Str::uuid();
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
        DB::table('users')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'first_name' => 'Permission',
            'last_name' => 'Administrator',
            'email' => 'permissions@example.test',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('roles')->insert([
            [
                'role_id' => $localRoleId,
                'organization_id' => $organizationId,
                'role_name' => 'Permission Administrator',
                'role_code' => 'permission_administrator',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'role_id' => $foreignRoleId,
                'organization_id' => $foreignOrganizationId,
                'role_name' => 'Foreign Permission Administrator',
                'role_code' => 'foreign_permission_administrator',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $grantForeignPermission ? $foreignRoleId : $localRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissions = [];
        foreach ([
            'permissions.manage' => ['Manage permissions', 'View and assign the permission catalog'],
            'roles.manage' => ['Manage roles', null],
            'users.manage' => ['Manage users', null],
        ] as $code => [$name, $description]) {
            $permissionId = (string) Str::uuid();
            $permissions[$code] = $permissionId;
            DB::table('permissions')->insert([
                'permission_id' => $permissionId,
                'permission_code' => $code,
                'permission_name' => $name,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($grantLocalPermission || $grantForeignPermission) {
            DB::table('role_permissions')->insert([
                'role_id' => $grantForeignPermission ? $foreignRoleId : $localRoleId,
                'permission_id' => $permissions['permissions.manage'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'user_id' => $userId,
            'token' => User::query()
                ->findOrFail($userId)
                ->createToken('Permission catalog test', ['*'], now()->addHour())
                ->plainTextToken,
            'permissions' => $permissions,
        ];
    }
}
