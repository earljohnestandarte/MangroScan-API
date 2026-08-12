<?php

namespace Tests\Feature\Site;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteIndexTest extends TestCase
{
    use RefreshDatabase;

    // [SITE-01] Listing is tenant-scoped, paginated, safely serialized, and side-effect free.
    public function test_it_lists_only_current_organization_sites_with_geojson(): void
    {
        $identity = $this->createSiteGraph();

        $response = $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_site_01_success')
            ->getJson('/api/v1/sites?per_page=1&page=1');

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_site_01_success')
            ->assertJsonPath('meta', [
                'request_id' => 'req_site_01_success',
                'page' => 1,
                'per_page' => 1,
                'total' => 2,
                'last_page' => 2,
            ])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.site_id', $identity['alpha_site_id'])
            ->assertJsonPath('data.0.organization_id', $identity['organization_id'])
            ->assertJsonPath('data.0.center_point.type', 'Point')
            ->assertJsonPath('data.0.center_point.coordinates.0', 123.9)
            ->assertJsonPath('data.0.center_point.coordinates.1', 10.2)
            ->assertJsonPath('data.0.area_hectares', '12.3456');

        $this->assertSame([
            'site_id',
            'organization_id',
            'site_name',
            'site_code',
            'description',
            'province',
            'city_municipality',
            'barangay',
            'center_point',
            'area_hectares',
            'environment_type',
            'access_notes',
            'status',
            'created_by',
            'created_at',
            'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [SITE-01] Search, status, and province filters compose case-insensitively where appropriate.
    public function test_it_applies_site_filters(): void
    {
        $identity = $this->createSiteGraph();

        $this->withToken($identity['token'])
            ->getJson('/api/v1/sites?search=central&status=ACTIVE&province=NEGROS%20ORIENTAL')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.site_id', $identity['alpha_site_id']);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/sites?status=archived&province=Cebu')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.site_id', $identity['beta_site_id']);
    }

    // [SITE-01] Query validation rejects unknown states and unsafe pagination bounds.
    public function test_it_validates_site_filters(): void
    {
        $identity = $this->createSiteGraph();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_site_01_validation')
            ->getJson('/api/v1/sites?status=pending&page=0&per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.request_id', 'req_site_01_validation')
            ->assertJsonValidationErrors(['status', 'page', 'per_page'], 'error.details');
    }

    // [SITE-01] Authentication and sites.read are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/sites')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $identity = $this->createSiteGraph(grantLocalPermission: false);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/sites')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.details.required_permission', 'sites.read');

    }

    // [SITE-01] A permission inherited only from a foreign-organization role is ignored.
    public function test_it_rejects_a_foreign_tenant_permission_grant(): void
    {
        $foreignGrant = $this->createSiteGraph(
            grantLocalPermission: false,
            grantForeignPermission: true,
        );

        $this->withToken($foreignGrant['token'])
            ->getJson('/api/v1/sites')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'sites.read');
    }

    // [SITE-01] Inactive identities cannot enumerate their organization's sites.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $identity = $this->createSiteGraph();
        DB::table('users')->where('user_id', $identity['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/sites')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [SITE-01] Site reads share the authenticated request budget.
    public function test_it_rate_limits_site_list_requests(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createSiteGraph();

        $this->withToken($identity['token'])->getJson('/api/v1/sites')->assertOk();

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_site_01_throttled')
            ->getJson('/api/v1/sites')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_site_01_throttled');
    }

    /**
     * @return array{
     *     actor_id: string,
     *     organization_id: string,
     *     alpha_site_id: string,
     *     beta_site_id: string,
     *     token: string
     * }
     */
    private function createSiteGraph(
        bool $grantLocalPermission = true,
        bool $grantForeignPermission = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
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

        $this->insertUser($actorId, $organizationId, 'sites@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, 'foreign-sites@example.test');

        DB::table('roles')->insert([
            [
                'role_id' => $localRoleId,
                'organization_id' => $organizationId,
                'role_name' => 'Site Reader',
                'role_code' => 'site_reader',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'role_id' => $foreignRoleId,
                'organization_id' => $foreignOrganizationId,
                'role_name' => 'Foreign Site Reader',
                'role_code' => 'foreign_site_reader',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('permissions')->insert([
            'permission_id' => $permissionId,
            'permission_code' => 'sites.read',
            'permission_name' => 'Read survey sites',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignments = [];

        if ($grantLocalPermission) {
            DB::table('role_permissions')->insert([
                'role_id' => $localRoleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $assignments[] = [
                'user_id' => $actorId,
                'role_id' => $localRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($grantForeignPermission) {
            DB::table('role_permissions')->insert([
                'role_id' => $foreignRoleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $assignments[] = [
                'user_id' => $actorId,
                'role_id' => $foreignRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($assignments !== []) {
            DB::table('user_roles')->insert($assignments);
        }

        $alphaSiteId = (string) Str::uuid();
        $betaSiteId = (string) Str::uuid();
        $deletedSiteId = (string) Str::uuid();
        $foreignSiteId = (string) Str::uuid();

        $this->insertSite(
            $alphaSiteId,
            $organizationId,
            $actorId,
            'Alpha Central Mangrove',
            'SITE-ALPHA',
            'Negros Oriental',
            'Dumaguete City',
            'active',
            ['type' => 'Point', 'coordinates' => [123.9, 10.2]],
            'Central validation site',
            '12.3456',
        );
        $this->insertSite(
            $betaSiteId,
            $organizationId,
            $actorId,
            'Beta Estuary',
            'SITE-BETA',
            'Cebu',
            'Cebu City',
            'archived',
        );
        $this->insertSite(
            $deletedSiteId,
            $organizationId,
            $actorId,
            'Deleted Site',
            'SITE-DELETED',
            'Cebu',
            'Cebu City',
            'active',
            deletedAt: now(),
        );
        $this->insertSite(
            $foreignSiteId,
            $foreignOrganizationId,
            $foreignUserId,
            'Foreign Site',
            'SITE-FOREIGN',
            'Palawan',
            'Puerto Princesa',
            'active',
        );

        return [
            'actor_id' => $actorId,
            'organization_id' => $organizationId,
            'alpha_site_id' => $alphaSiteId,
            'beta_site_id' => $betaSiteId,
            'token' => User::query()
                ->findOrFail($actorId)
                ->createToken('Site list test', ['*'], now()->addHour())
                ->plainTextToken,
        ];
    }

    private function insertUser(string $userId, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'first_name' => 'Site',
            'last_name' => 'Reader',
            'email' => $email,
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array{type: string, coordinates: array{float, float}}|null  $centerPoint
     */
    private function insertSite(
        string $siteId,
        string $organizationId,
        string $createdBy,
        string $siteName,
        string $siteCode,
        string $province,
        string $cityMunicipality,
        string $status,
        ?array $centerPoint = null,
        ?string $description = null,
        ?string $areaHectares = null,
        ?DateTimeInterface $deletedAt = null,
    ): void {
        DB::table('survey_sites')->insert([
            'site_id' => $siteId,
            'organization_id' => $organizationId,
            'site_name' => $siteName,
            'site_code' => $siteCode,
            'description' => $description,
            'province' => $province,
            'city_municipality' => $cityMunicipality,
            'environment_type' => 'estuarine',
            'area_hectares' => $areaHectares,
            'status' => $status,
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deletedAt,
        ]);

        if ($centerPoint === null) {
            return;
        }

        $geoJson = json_encode($centerPoint, JSON_THROW_ON_ERROR);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'UPDATE survey_sites SET center_point = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) WHERE site_id = ?',
                [$geoJson, $siteId],
            );

            return;
        }

        DB::table('survey_sites')->where('site_id', $siteId)->update([
            'center_point' => $geoJson,
        ]);
    }
}
