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

class OrganizationUpdateTest extends TestCase
{
    use RefreshDatabase;

    // [ORG-04] System administrators atomically update metadata and archive by inactive status.
    public function test_it_updates_and_deactivates_an_organization_with_audit_evidence(): void
    {
        $identity = $this->createIdentityGraph();
        $uri = '/api/v1/organizations/'.$identity['target_organization_id'];

        $response = $this->withToken($identity['token'])
            ->withHeaders([
                'X-Request-ID' => 'req_org_04_success',
                'User-Agent' => 'MangroScan Organization Update Test',
            ])
            ->patchJson($uri, [
                'organization_name' => ' Updated Coastal Office ',
                'organization_type' => ' DENR ',
                'contact_email' => null,
                'contact_number' => ' +63 900 222 0101 ',
                'address' => ' Updated Coastal Province ',
                'status' => ' INACTIVE ',
            ]);

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_org_04_success')
            ->assertJsonPath('data.organization_id', $identity['target_organization_id'])
            ->assertJsonPath('data.organization_name', 'Updated Coastal Office')
            ->assertJsonPath('data.organization_type', 'denr')
            ->assertJsonPath('data.contact_email', null)
            ->assertJsonPath('data.contact_number', '+63 900 222 0101')
            ->assertJsonPath('data.address', 'Updated Coastal Province')
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('meta.request_id', 'req_org_04_success');

        $this->assertDatabaseHas('organizations', [
            'organization_id' => $identity['target_organization_id'],
            'organization_name' => 'Updated Coastal Office',
            'organization_type' => 'denr',
            'contact_email' => null,
            'status' => 'inactive',
            'deleted_at' => null,
        ]);

        $audit = AuditLog::query()->sole();
        $this->assertSame('organization.update', $audit->action);
        $this->assertSame('organizations', $audit->table_name);
        $this->assertSame($identity['target_organization_id'], $audit->record_id);
        $this->assertSame($identity['actor_id'], $audit->user_id);
        $this->assertSame('req_org_04_success', $audit->request_id);
        $this->assertSame('Target Organization', $audit->old_values['organization_name']);
        $this->assertSame('Updated Coastal Office', $audit->new_values['organization_name']);
        $this->assertSame('active', $audit->old_values['status']);
        $this->assertSame('inactive', $audit->new_values['status']);
    }

    // [ORG-04] A true partial update preserves omitted metadata and may clear nullable fields.
    public function test_it_preserves_omitted_fields_and_clears_nullable_fields(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->patchJson('/api/v1/organizations/'.$identity['target_organization_id'], [
                'contact_email' => null,
                'address' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.organization_name', 'Target Organization')
            ->assertJsonPath('data.organization_type', 'ngo')
            ->assertJsonPath('data.contact_email', null)
            ->assertJsonPath('data.contact_number', '+63 900 111 0101')
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.status', 'active');
    }

    // [ORG-04] Empty, unknown and malformed changes fail validation before persistence.
    public function test_it_validates_partial_updates(): void
    {
        $identity = $this->createIdentityGraph();
        $uri = '/api/v1/organizations/'.$identity['target_organization_id'];

        $this->withToken($identity['token'])
            ->patchJson($uri, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['request'], 'error.details');

        $this->withToken($identity['token'])
            ->patchJson($uri, ['unknown_field' => 'value'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['request'], 'error.details');

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_org_04_validation')
            ->patchJson($uri, [
                'organization_name' => ' ',
                'organization_type' => 'university',
                'contact_email' => 'not-an-email',
                'contact_number' => str_repeat('1', 51),
                'status' => 'archived',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.request_id', 'req_org_04_validation')
            ->assertJsonValidationErrors([
                'organization_name',
                'organization_type',
                'contact_email',
                'contact_number',
                'status',
            ], 'error.details');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [ORG-04] Active and archived names remain reserved case-insensitively.
    public function test_it_rejects_duplicate_and_archived_organization_names(): void
    {
        $identity = $this->createIdentityGraph();
        $uri = '/api/v1/organizations/'.$identity['target_organization_id'];

        foreach ([' duplicate organization ', ' ARCHIVED RESERVATION '] as $name) {
            $this->withToken($identity['token'])
                ->patchJson($uri, ['organization_name' => $name])
                ->assertConflict()
                ->assertJsonPath('error.code', 'CONFLICT');
        }

        $this->assertDatabaseHas('organizations', [
            'organization_id' => $identity['target_organization_id'],
            'organization_name' => 'Target Organization',
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [ORG-04] Administrators cannot deactivate the organization authenticating their request.
    public function test_it_prevents_self_organization_deactivation(): void
    {
        $identity = $this->createIdentityGraph();

        $this->withToken($identity['token'])
            ->patchJson('/api/v1/organizations/'.$identity['organization_id'], [
                'status' => 'inactive',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.details.organization_id', $identity['organization_id']);

        $this->assertDatabaseHas('organizations', [
            'organization_id' => $identity['organization_id'],
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [ORG-04] Missing, malformed and archived targets share the standard 404.
    public function test_it_hides_unavailable_organizations(): void
    {
        $identity = $this->createIdentityGraph();

        foreach ([
            (string) Str::uuid(),
            'not-a-uuid',
            $identity['archived_organization_id'],
        ] as $organizationId) {
            $this->withToken($identity['token'])
                ->patchJson('/api/v1/organizations/'.$organizationId, [
                    'address' => 'Unavailable',
                ])
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    // [ORG-04] Audit persistence failure rolls back every metadata change.
    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $identity = $this->createIdentityGraph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($identity['token'])
            ->patchJson('/api/v1/organizations/'.$identity['target_organization_id'], [
                'organization_name' => 'Rolled Back Name',
                'status' => 'inactive',
            ])
            ->assertInternalServerError();

        $this->assertDatabaseHas('organizations', [
            'organization_id' => $identity['target_organization_id'],
            'organization_name' => 'Target Organization',
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [ORG-04] Authentication and organizations.manage are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->patchJson('/api/v1/organizations/'.Str::uuid(), ['address' => 'No'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $identity = $this->createIdentityGraph(grantLocalPermission: false);

        $this->withToken($identity['token'])
            ->patchJson('/api/v1/organizations/'.$identity['target_organization_id'], [
                'address' => 'No',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.details.required_permission', 'organizations.manage');
    }

    // [ORG-04] Foreign-tenant roles cannot authorize organization changes.
    public function test_foreign_organization_permission_does_not_authorize_updates(): void
    {
        $identity = $this->createIdentityGraph(
            grantLocalPermission: false,
            grantForeignPermission: true,
        );

        $this->withToken($identity['token'])
            ->patchJson('/api/v1/organizations/'.$identity['target_organization_id'], [
                'address' => 'No',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // [ORG-04] Updates consume the shared authenticated request budget.
    public function test_it_rate_limits_organization_updates(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentityGraph();
        $uri = '/api/v1/organizations/'.$identity['target_organization_id'];

        $this->withToken($identity['token'])
            ->patchJson($uri, ['address' => 'First Address'])
            ->assertOk();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_org_04_throttled')
            ->patchJson($uri, ['address' => 'Second Address'])
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_org_04_throttled');

        $this->assertDatabaseHas('organizations', [
            'organization_id' => $identity['target_organization_id'],
            'address' => 'First Address',
        ]);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [ORG-04] Existing identity DCL permits update and append-only audit evidence.
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
        $this->assertStringContainsString(
            'REVOKE UPDATE, DELETE, TRUNCATE ON TABLE app.audit_logs',
            $dcl,
        );
    }

    /**
     * @return array{
     *     actor_id: string,
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
        $duplicateOrganizationId = (string) Str::uuid();
        $archivedOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $permissionId = (string) Str::uuid();

        DB::table('organizations')->insert([
            $this->organization($organizationId, 'System Administrator Home', 'research_group'),
            $this->organization($targetOrganizationId, 'Target Organization', 'ngo', [
                'contact_email' => 'old-target@example.test',
                'contact_number' => '+63 900 111 0101',
                'address' => 'Original Coastal Province',
            ]),
            $this->organization($duplicateOrganizationId, 'Duplicate Organization', 'lgu'),
            $this->organization($archivedOrganizationId, 'Archived Reservation', 'ngo', [
                'status' => 'inactive',
                'deleted_at' => now(),
            ]),
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
                'role_code' => 'organization_update_administrator_'.Str::lower(Str::random(8)),
                'is_system_role' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'role_id' => $foreignRoleId,
                'organization_id' => $targetOrganizationId,
                'role_name' => 'Foreign Organization Administrator',
                'role_code' => 'foreign_update_administrator_'.Str::lower(Str::random(8)),
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
            'target_organization_id' => $targetOrganizationId,
            'archived_organization_id' => $archivedOrganizationId,
            'token' => $actor->createToken('organization-update-test')->plainTextToken,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function organization(
        string $organizationId,
        string $name,
        string $type,
        array $overrides = [],
    ): array {
        return [
            'organization_id' => $organizationId,
            'organization_name' => $name,
            'organization_type' => $type,
            'contact_email' => null,
            'contact_number' => null,
            'address' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
            ...$overrides,
        ];
    }
}
