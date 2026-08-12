<?php

namespace Tests\Feature\Organization;

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

class OrganizationStoreTest extends TestCase
{
    use RefreshDatabase;

    // [ORG-02] A system administrator creates normalized organization metadata and audit evidence.
    public function test_it_creates_an_organization_with_immutable_audit_evidence(): void
    {
        $identity = $this->createIdentityGraph();

        $response = $this->withToken($identity['token'])
            ->withHeaders([
                'X-Request-ID' => 'req_org_02_success',
                'User-Agent' => 'MangroScan Organization Test',
            ])
            ->postJson('/api/v1/organizations', $this->payload());

        $response
            ->assertCreated()
            ->assertHeader('X-Request-ID', 'req_org_02_success')
            ->assertJsonPath('data.organization_name', 'Delta Mangrove Institute')
            ->assertJsonPath('data.organization_type', 'school')
            ->assertJsonPath('data.contact_email', 'contact@delta.test')
            ->assertJsonPath('data.contact_number', '+63 900 555 0101')
            ->assertJsonPath('data.address', 'Dumaguete City')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('meta.request_id', 'req_org_02_success');

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

        $organizationId = $response->json('data.organization_id');
        $this->assertDatabaseHas('organizations', [
            'organization_id' => $organizationId,
            'organization_name' => 'Delta Mangrove Institute',
            'organization_type' => 'school',
            'contact_email' => 'contact@delta.test',
            'status' => 'active',
        ]);

        $audit = AuditLog::query()->sole();
        $this->assertSame('organization.create', $audit->action);
        $this->assertSame('organizations', $audit->table_name);
        $this->assertSame($organizationId, $audit->record_id);
        $this->assertSame($identity['actor_id'], $audit->user_id);
        $this->assertSame('req_org_02_success', $audit->request_id);
        $this->assertSame('Delta Mangrove Institute', $audit->new_values['organization_name']);
        $this->assertSame('school', $audit->new_values['organization_type']);
        $this->assertArrayNotHasKey('created_at', $audit->new_values);
        $this->assertNull($audit->old_values);
    }

    // [ORG-02] Optional contact metadata may be omitted without inventing values.
    public function test_it_accepts_omitted_optional_metadata(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->postJson('/api/v1/organizations', [
                'organization_name' => 'New Local Government',
                'organization_type' => 'LGU',
            ])
            ->assertCreated()
            ->assertJsonPath('data.organization_type', 'lgu')
            ->assertJsonPath('data.contact_email', null)
            ->assertJsonPath('data.contact_number', null)
            ->assertJsonPath('data.address', null);
    }

    // [ORG-02] Required fields, documented types, email and storage lengths are validated.
    public function test_it_validates_organization_input(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_org_02_validation')
            ->postJson('/api/v1/organizations', [
                'organization_name' => ' ',
                'organization_type' => 'university',
                'contact_email' => 'not-an-email',
                'contact_number' => str_repeat('1', 51),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.request_id', 'req_org_02_validation')
            ->assertJsonValidationErrors([
                'organization_name',
                'organization_type',
                'contact_email',
                'contact_number',
            ], 'error.details');

        $this->assertDatabaseCount('organizations', 3);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [ORG-02] Organization names are case-insensitively reserved, including archived records.
    public function test_it_rejects_duplicate_and_archived_organization_names(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->postJson('/api/v1/organizations', [
                'organization_name' => ' archived owner ',
                'organization_type' => 'ngo',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.details.organization_name', 'archived owner');

        $this->withToken($identity['token'])
            ->postJson('/api/v1/organizations', $this->payload())
            ->assertCreated();

        $duplicate = $this->payload();
        $duplicate['organization_name'] = 'DELTA MANGROVE INSTITUTE';

        $this->withToken($identity['token'])
            ->postJson('/api/v1/organizations', $duplicate)
            ->assertConflict()
            ->assertJsonPath('error.code', 'CONFLICT');

        $this->assertDatabaseCount('organizations', 4);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [ORG-02] Audit persistence failure rolls back the new tenant atomically.
    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $identity = $this->createIdentityGraph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($identity['token'])
            ->postJson('/api/v1/organizations', $this->payload())
            ->assertInternalServerError();

        $this->assertDatabaseMissing('organizations', [
            'organization_name' => 'Delta Mangrove Institute',
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [ORG-02] Authentication and organizations.manage are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->postJson('/api/v1/organizations', $this->payload())
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $identity = $this->createIdentityGraph(grantLocalPermission: false);

        $this->withToken($identity['token'])
            ->postJson('/api/v1/organizations', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.details.required_permission', 'organizations.manage');
    }

    // [ORG-02] Foreign-tenant roles cannot authorize global tenant creation.
    public function test_foreign_organization_permission_does_not_authorize_creation(): void
    {
        $identity = $this->createIdentityGraph(
            grantLocalPermission: false,
            grantForeignPermission: true,
        );

        $this->withToken($identity['token'])
            ->postJson('/api/v1/organizations', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // [ORG-02] Inactive identities cannot create a tenant.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $identity = $this->createIdentityGraph();
        DB::table('users')
            ->where('user_id', $identity['actor_id'])
            ->update(['status' => 'inactive']);

        $this->withToken($identity['token'])
            ->postJson('/api/v1/organizations', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [ORG-02] Creation consumes the shared authenticated request budget.
    public function test_it_rate_limits_organization_creation(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->postJson('/api/v1/organizations', $this->payload())
            ->assertCreated();

        $next = $this->payload();
        $next['organization_name'] = 'Second Organization';

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_org_02_throttled')
            ->postJson('/api/v1/organizations', $next)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_org_02_throttled');

        $this->assertDatabaseCount('organizations', 4);
    }

    // [ORG-02] Existing identity DCL permits the transactional insert and append-only audit.
    public function test_it_reuses_the_versioned_identity_and_audit_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/002_identity_and_audit_grants.sql'));

        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE', $dcl);
        $this->assertStringContainsString('app.organizations,', $dcl);
        $this->assertStringContainsString(
            'GRANT SELECT, INSERT ON TABLE app.audit_logs TO mangroscan_api_rw;',
            $dcl,
        );
        $this->assertStringNotContainsString('TO mangroscan_report_ro;', $dcl);
    }

    /**
     * @return array<string, string>
     */
    private function payload(): array
    {
        return [
            'organization_name' => ' Delta Mangrove Institute ',
            'organization_type' => ' SCHOOL ',
            'contact_email' => ' CONTACT@DELTA.TEST ',
            'contact_number' => ' +63 900 555 0101 ',
            'address' => ' Dumaguete City ',
        ];
    }

    /**
     * @return array{actor_id: string, organization_id: string, token: string}
     */
    private function createIdentityGraph(
        bool $grantLocalPermission = true,
        bool $grantForeignPermission = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
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
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'organization_id' => $foreignOrganizationId,
                'organization_name' => 'Foreign Organization',
                'organization_type' => 'ngo',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'organization_id' => $archivedOrganizationId,
                'organization_name' => 'Archived Owner',
                'organization_type' => 'ngo',
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
                'role_code' => 'organization_administrator_'.Str::lower(Str::random(8)),
                'is_system_role' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'role_id' => $foreignRoleId,
                'organization_id' => $foreignOrganizationId,
                'role_name' => 'Foreign Organization Administrator',
                'role_code' => 'foreign_organization_administrator_'.Str::lower(Str::random(8)),
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
            'actor_id' => $actorId,
            'organization_id' => $organizationId,
            'token' => $actor->createToken('organization-store-test')->plainTextToken,
        ];
    }
}
