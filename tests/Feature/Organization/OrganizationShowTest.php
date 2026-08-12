<?php

namespace Tests\Feature\Organization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationShowTest extends TestCase
{
    use RefreshDatabase;

    // [ORG-03] System administrators can inspect a foreign tenant's exact safe metadata.
    public function test_it_returns_organization_detail_with_exact_resource_fields(): void
    {
        $identity = $this->createIdentityGraph();

        $response = $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_org_03_success')
            ->getJson('/api/v1/organizations/'.$identity['target_organization_id']);

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_org_03_success')
            ->assertJsonPath('data.organization_id', $identity['target_organization_id'])
            ->assertJsonPath('data.organization_name', 'Target Coastal Organization')
            ->assertJsonPath('data.organization_type', 'ngo')
            ->assertJsonPath('data.contact_email', 'target@example.test')
            ->assertJsonPath('data.contact_number', '+63 900 888 0101')
            ->assertJsonPath('data.address', 'Target Coastal Province')
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('meta.request_id', 'req_org_03_success');

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
        ], array_keys($response->json('data')));
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [ORG-03] Missing, malformed and archived identifiers share the standard 404 envelope.
    public function test_it_hides_unavailable_organizations(): void
    {
        $identity = $this->createIdentityGraph();

        foreach ([
            (string) Str::uuid(),
            'not-a-uuid',
            $identity['archived_organization_id'],
        ] as $organizationId) {
            $this->withToken($identity['token'])
                ->withHeader('X-Request-ID', 'req_org_03_not_found')
                ->getJson('/api/v1/organizations/'.$organizationId)
                ->assertNotFound()
                ->assertExactJson([
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => 'The requested resource was not found.',
                        'details' => [],
                        'request_id' => 'req_org_03_not_found',
                    ],
                ]);
        }
    }

    // [ORG-03] Authentication and organizations.manage are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/organizations/'.Str::uuid())
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $identity = $this->createIdentityGraph(grantLocalPermission: false);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/organizations/'.$identity['target_organization_id'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.details.required_permission', 'organizations.manage');
    }

    // [ORG-03] Permission inherited only through a foreign-tenant role is ignored.
    public function test_foreign_organization_permission_does_not_authorize_detail(): void
    {
        $identity = $this->createIdentityGraph(
            grantLocalPermission: false,
            grantForeignPermission: true,
        );

        $this->withToken($identity['token'])
            ->getJson('/api/v1/organizations/'.$identity['target_organization_id'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // [ORG-03] Inactive callers cannot inspect another tenant.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $identity = $this->createIdentityGraph();
        DB::table('organizations')
            ->where('organization_id', $identity['organization_id'])
            ->update(['status' => 'inactive']);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/organizations/'.$identity['target_organization_id'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [ORG-03] Detail reads consume the shared authenticated request budget.
    public function test_it_rate_limits_organization_detail_requests(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentityGraph();
        $uri = '/api/v1/organizations/'.$identity['target_organization_id'];

        $this->withToken($identity['token'])->getJson($uri)->assertOk();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_org_03_throttled')
            ->getJson($uri)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_org_03_throttled');
    }

    // [ORG-03] The established identity DCL permits API detail reads only.
    public function test_it_reuses_the_versioned_identity_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/002_identity_and_audit_grants.sql'));

        $this->assertIsString($dcl);
        $this->assertStringContainsString('app.organizations,', $dcl);
        $this->assertStringContainsString('TO mangroscan_api_rw;', $dcl);
        $this->assertStringNotContainsString('TO mangroscan_report_ro;', $dcl);
        $this->assertStringNotContainsString('TO mangroscan_worker;', $dcl);
    }

    /**
     * @return array{
     *     organization_id: string,
     *     target_organization_id: string,
     *     archived_organization_id: string,
     *     token: string
     * }
     */
    private function createIdentityGraph(
        bool $grantLocalPermission = true,
        bool $grantForeignPermission = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $targetOrganizationId = (string) Str::uuid();
        $archivedOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $permissionId = (string) Str::uuid();

        DB::table('organizations')->insert([
            [
                'organization_id' => $organizationId,
                'organization_name' => 'System Administrator Home',
                'organization_type' => 'research_group',
                'contact_email' => null,
                'contact_number' => null,
                'address' => null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'organization_id' => $targetOrganizationId,
                'organization_name' => 'Target Coastal Organization',
                'organization_type' => 'ngo',
                'contact_email' => 'target@example.test',
                'contact_number' => '+63 900 888 0101',
                'address' => 'Target Coastal Province',
                'status' => 'inactive',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'organization_id' => $archivedOrganizationId,
                'organization_name' => 'Archived Coastal Organization',
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
            'user_id' => $actorId,
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
                'role_name' => 'Organization Administrator',
                'role_code' => 'organization_detail_administrator_'.Str::lower(Str::random(8)),
                'is_system_role' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'role_id' => $foreignRoleId,
                'organization_id' => $targetOrganizationId,
                'role_name' => 'Foreign Organization Administrator',
                'role_code' => 'foreign_detail_administrator_'.Str::lower(Str::random(8)),
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

        if ($grantLocalPermission || $grantForeignPermission) {
            $roleId = $grantForeignPermission ? $foreignRoleId : $localRoleId;
            DB::table('role_permissions')->insert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('user_roles')->insert([
                'user_id' => $actorId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /** @var User $actor */
        $actor = User::query()->findOrFail($actorId);

        return [
            'organization_id' => $organizationId,
            'target_organization_id' => $targetOrganizationId,
            'archived_organization_id' => $archivedOrganizationId,
            'token' => $actor->createToken('organization-show-test')->plainTextToken,
        ];
    }
}
