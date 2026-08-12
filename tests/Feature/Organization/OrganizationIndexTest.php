<?php

namespace Tests\Feature\Organization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationIndexTest extends TestCase
{
    use RefreshDatabase;

    // [ORG-01] Authorized system administrators receive a global, stable, safe directory.
    public function test_it_lists_non_deleted_organizations_with_exact_pagination_metadata(): void
    {
        $identity = $this->createIdentityGraph();

        $response = $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_org_01_success')
            ->getJson('/api/v1/organizations?per_page=2&page=1');

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_org_01_success')
            ->assertJsonPath('meta', [
                'request_id' => 'req_org_01_success',
                'page' => 1,
                'per_page' => 2,
                'total' => 3,
                'last_page' => 2,
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.organization_id', $identity['alpha_organization_id'])
            ->assertJsonPath('data.1.organization_id', $identity['beta_organization_id']);

        foreach ($response->json('data') as $organization) {
            $this->assertSame([
                'organization_id',
                'organization_name',
                'organization_type',
                'contact_email',
                'contact_number',
                'address',
                'status',
                'created_at',
                'updated_at',
            ], array_keys($organization));
            $this->assertArrayNotHasKey('deleted_at', $organization);
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [ORG-01] Search spans documented metadata and composes with normalized status.
    public function test_it_applies_search_and_status_filters(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->getJson('/api/v1/organizations?search=%20CONTACT%40ALPHA.TEST%20&status=%20ACTIVE%20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.organization_id', $identity['alpha_organization_id']);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/organizations?search=government&status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.organization_id', $identity['beta_organization_id']);
    }

    // [ORG-01] Archived organizations are omitted from every directory page.
    public function test_it_excludes_soft_deleted_organizations(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->getJson('/api/v1/organizations?search=Archived')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    // [ORG-01] Filter and pagination values fail through the shared validation envelope.
    public function test_it_validates_directory_filters(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_org_01_validation')
            ->getJson('/api/v1/organizations?search[]=invalid&status=archived&page=0&per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.request_id', 'req_org_01_validation')
            ->assertJsonValidationErrors(
                ['search', 'status', 'page', 'per_page'],
                'error.details',
            );
    }

    // [ORG-01] Both authentication and organizations.manage are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/organizations')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $identity = $this->createIdentityGraph(grantLocalPermission: false);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/organizations')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.details.required_permission', 'organizations.manage');
    }

    // [ORG-01] A role belonging to another tenant cannot elevate the caller.
    public function test_foreign_organization_permission_does_not_authorize_the_directory(): void
    {
        $identity = $this->createIdentityGraph(
            grantLocalPermission: false,
            grantForeignPermission: true,
        );

        $this->withToken($identity['token'])
            ->getJson('/api/v1/organizations')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // [ORG-01] Inactive identities cannot inspect cross-tenant metadata.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $identity = $this->createIdentityGraph();
        DB::table('organizations')
            ->where('organization_id', $identity['organization_id'])
            ->update(['status' => 'inactive']);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/organizations')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [ORG-01] Directory reads consume the shared authenticated request budget.
    public function test_it_rate_limits_organization_directory_requests(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])->getJson('/api/v1/organizations')->assertOk();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_org_01_throttled')
            ->getJson('/api/v1/organizations')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_org_01_throttled');
    }

    // [ORG-01] The established identity DCL permits API reads without widening other roles.
    public function test_it_reuses_the_versioned_identity_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/002_identity_and_audit_grants.sql'));

        $this->assertIsString($dcl);
        $this->assertStringContainsString('app.organizations,', $dcl);
        $this->assertStringContainsString(
            'TO mangroscan_api_rw;',
            $dcl,
        );
        $this->assertStringNotContainsString('TO mangroscan_worker;', $dcl);
        $this->assertStringNotContainsString('TO mangroscan_report_ro;', $dcl);
    }

    /**
     * @return array{
     *     organization_id: string,
     *     alpha_organization_id: string,
     *     beta_organization_id: string,
     *     token: string
     * }
     */
    private function createIdentityGraph(
        bool $grantLocalPermission = true,
        bool $grantForeignPermission = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $alphaOrganizationId = (string) Str::uuid();
        $betaOrganizationId = (string) Str::uuid();
        $archivedOrganizationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $permissionId = (string) Str::uuid();

        DB::table('organizations')->insert([
            [
                'organization_id' => $organizationId,
                'organization_name' => 'System Admin Home',
                'organization_type' => 'research_group',
                'contact_email' => 'admin-home@example.test',
                'contact_number' => null,
                'address' => null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'organization_id' => $alphaOrganizationId,
                'organization_name' => 'Alpha Research Group',
                'organization_type' => 'research_group',
                'contact_email' => 'contact@alpha.test',
                'contact_number' => '+63 900 111 2222',
                'address' => 'Coastal Research Campus',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'organization_id' => $betaOrganizationId,
                'organization_name' => 'Beta Government Office',
                'organization_type' => 'lgu',
                'contact_email' => null,
                'contact_number' => null,
                'address' => 'Government Center',
                'status' => 'inactive',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'organization_id' => $archivedOrganizationId,
                'organization_name' => 'Archived Organization',
                'organization_type' => 'ngo',
                'contact_email' => null,
                'contact_number' => null,
                'address' => null,
                'status' => 'inactive',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => now(),
            ],
        ]);

        DB::table('users')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'first_name' => 'System',
            'last_name' => 'Administrator',
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('roles')->insert([
            [
                'role_id' => $localRoleId,
                'organization_id' => $organizationId,
                'role_name' => 'System Administrator',
                'role_code' => 'system_administrator_'.Str::lower(Str::random(8)),
                'is_system_role' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'role_id' => $foreignRoleId,
                'organization_id' => $alphaOrganizationId,
                'role_name' => 'Foreign Administrator',
                'role_code' => 'foreign_administrator_'.Str::lower(Str::random(8)),
                'is_system_role' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('permissions')->insert([
            'permission_id' => $permissionId,
            'permission_code' => 'organizations.manage',
            'permission_name' => 'Manage organizations',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_permissions')->insert([
            'role_id' => $grantForeignPermission ? $foreignRoleId : $localRoleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $grantForeignPermission ? $foreignRoleId : $localRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $grantLocalPermission && ! $grantForeignPermission) {
            DB::table('role_permissions')->delete();
        }

        /** @var User $user */
        $user = User::query()->findOrFail($userId);

        return [
            'organization_id' => $organizationId,
            'alpha_organization_id' => $alphaOrganizationId,
            'beta_organization_id' => $betaOrganizationId,
            'token' => $user->createToken('org-index-test')->plainTextToken,
        ];
    }
}
