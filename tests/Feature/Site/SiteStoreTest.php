<?php

namespace Tests\Feature\Site;

use App\Models\AuditLog;
use App\Models\SurveySite;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SiteStoreTest extends TestCase
{
    use RefreshDatabase;

    // [SITE-02] An authorized caller creates a normalized tenant-owned PostGIS site and audit.
    public function test_it_creates_a_site_with_geojson_and_audit_evidence(): void
    {
        $identity = $this->createIdentity();
        $payload = $this->validPayload();
        $payload['organization_id'] = (string) Str::uuid();
        $payload['center_point']['coordinates'] = ['123.9000', '10.2000'];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$identity['token'],
            'X-Request-ID' => 'req_site_02_success',
            'User-Agent' => 'MangroScan GIS Test',
        ])->postJson('/api/v1/sites', $payload);

        $response
            ->assertCreated()
            ->assertHeader('X-Request-ID', 'req_site_02_success')
            ->assertJsonPath('data.organization_id', $identity['organization_id'])
            ->assertJsonPath('data.site_name', 'Foundation Mangrove Site')
            ->assertJsonPath('data.site_code', 'SITE-NEW-001')
            ->assertJsonPath('data.environment_type', 'estuarine')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.created_by', $identity['actor_id'])
            ->assertJsonPath('data.center_point.type', 'Point')
            ->assertJsonPath('data.center_point.coordinates.0', 123.9)
            ->assertJsonPath('data.center_point.coordinates.1', 10.2)
            ->assertJsonPath('data.area_hectares', '12.3456')
            ->assertJsonPath('meta.request_id', 'req_site_02_success');

        $siteId = $response->json('data.site_id');
        $site = SurveySite::query()->withCenterPointGeoJson()->findOrFail($siteId);
        $this->assertSame($identity['organization_id'], $site->organization_id);
        $this->assertSame($identity['actor_id'], $site->created_by);

        $audit = AuditLog::query()->sole();
        $this->assertSame('site.create', $audit->action);
        $this->assertSame('survey_sites', $audit->table_name);
        $this->assertSame($siteId, $audit->record_id);
        $this->assertSame($identity['actor_id'], $audit->user_id);
        $this->assertNull($audit->old_values);
        $this->assertSame('SITE-NEW-001', $audit->new_values['site_code']);
        $this->assertNotSame($payload['organization_id'], $identity['organization_id']);
        $this->assertSame($identity['organization_id'], $audit->new_values['organization_id']);
        $this->assertSame('req_site_02_success', $audit->request_id);
        $this->assertSame('MangroScan GIS Test', $audit->user_agent);
    }

    // [SITE-02] The full creation contract, geometry bounds, precision, and normalized code uniqueness are validated.
    public function test_it_validates_site_creation_input_and_uniqueness(): void
    {
        $identity = $this->createIdentity();

        $this->withToken($identity['token'])
            ->postJson('/api/v1/sites', $this->validPayload())
            ->assertCreated();

        $invalid = [
            'site_name' => ' ',
            'site_code' => ' site-new-001 ',
            'province' => '',
            'city_municipality' => '',
            'environment_type' => 'oceanic',
            'area_hectares' => '1.23456',
            'center_point' => [
                'type' => 'Polygon',
                'coordinates' => [181, 91],
            ],
        ];

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_site_02_validation')
            ->postJson('/api/v1/sites', $invalid)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.request_id', 'req_site_02_validation')
            ->assertJsonValidationErrors([
                'site_name',
                'site_code',
                'province',
                'city_municipality',
                'environment_type',
                'area_hectares',
                'center_point.type',
                'center_point.coordinates.0',
                'center_point.coordinates.1',
            ], 'error.details');

        $this->assertDatabaseCount('survey_sites', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [SITE-02] Optional nullable fields, including center point, remain nullable.
    public function test_it_creates_a_site_without_optional_fields(): void
    {
        $identity = $this->createIdentity();
        $payload = $this->validPayload();
        unset(
            $payload['description'],
            $payload['barangay'],
            $payload['center_point'],
            $payload['area_hectares'],
            $payload['access_notes'],
        );

        $this->withToken($identity['token'])
            ->postJson('/api/v1/sites', $payload)
            ->assertCreated()
            ->assertJsonPath('data.center_point', null)
            ->assertJsonPath('data.area_hectares', null)
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.barangay', null)
            ->assertJsonPath('data.access_notes', null);
    }

    // [SITE-02] Mandatory audit failure rolls the site insertion back.
    public function test_it_rolls_back_creation_when_audit_persistence_fails(): void
    {
        $identity = $this->createIdentity();
        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $auditLogger);

        $this->withToken($identity['token'])
            ->postJson('/api/v1/sites', $this->validPayload())
            ->assertInternalServerError();

        $this->assertDatabaseCount('survey_sites', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [SITE-02] Authentication and a current-tenant sites.manage permission are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->postJson('/api/v1/sites', $this->validPayload())
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $identity = $this->createIdentity(grantPermission: false);

        $this->withToken($identity['token'])
            ->postJson('/api/v1/sites', $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'sites.manage');

        $this->assertDatabaseCount('survey_sites', 0);
    }

    // [SITE-02] A sites.manage grant from a foreign-organization role cannot authorize creation.
    public function test_it_rejects_a_foreign_tenant_permission_grant(): void
    {
        $identity = $this->createIdentity(grantPermissionThroughForeignRole: true);

        $this->withToken($identity['token'])
            ->postJson('/api/v1/sites', $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'sites.manage');

        $this->assertDatabaseCount('survey_sites', 0);
    }

    // [SITE-02] Throttling prevents a second insertion and audit event.
    public function test_it_rate_limits_site_creation(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentity();

        $this->withToken($identity['token'])
            ->postJson('/api/v1/sites', $this->validPayload())
            ->assertCreated();

        $second = $this->validPayload();
        $second['site_code'] = 'SITE-NEW-002';
        $this->withToken($identity['token'])
            ->postJson('/api/v1/sites', $second)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');

        $this->assertDatabaseCount('survey_sites', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'site_name' => ' Foundation Mangrove Site ',
            'site_code' => ' site-new-001 ',
            'description' => ' Long-term validation site ',
            'province' => ' Negros Oriental ',
            'city_municipality' => ' Dumaguete City ',
            'barangay' => ' Banilad ',
            'center_point' => [
                'type' => 'Point',
                'coordinates' => [123.9, 10.2],
            ],
            'area_hectares' => '12.3456',
            'environment_type' => ' ESTUARINE ',
            'access_notes' => ' Coordinate with the field office ',
        ];
    }

    /**
     * @return array{actor_id: string, organization_id: string, token: string}
     */
    private function createIdentity(
        bool $grantPermission = true,
        bool $grantPermissionThroughForeignRole = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $permissionId = (string) Str::uuid();

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
            'first_name' => 'Site',
            'last_name' => 'Manager',
            'email' => 'site-manager@example.test',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('roles')->insert([
            [
                'role_id' => $localRoleId,
                'organization_id' => $organizationId,
                'role_name' => 'Site Manager',
                'role_code' => 'site_manager',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'role_id' => $foreignRoleId,
                'organization_id' => $foreignOrganizationId,
                'role_name' => 'Foreign Site Manager',
                'role_code' => 'foreign_site_manager',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('permissions')->insert([
            'permission_id' => $permissionId,
            'permission_code' => 'sites.manage',
            'permission_name' => 'Manage sites',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($grantPermission || $grantPermissionThroughForeignRole) {
            $roleId = $grantPermissionThroughForeignRole ? $foreignRoleId : $localRoleId;
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

        return [
            'actor_id' => $actorId,
            'organization_id' => $organizationId,
            'token' => User::query()
                ->findOrFail($actorId)
                ->createToken('Site creation test', ['*'], now()->addHour())
                ->plainTextToken,
        ];
    }
}
