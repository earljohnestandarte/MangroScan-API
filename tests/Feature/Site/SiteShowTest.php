<?php

namespace Tests\Feature\Site;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteShowTest extends TestCase
{
    use RefreshDatabase;

    // [SITE-03] Detail returns the safe GeoJSON site and stable child-count contract.
    public function test_it_returns_a_tenant_scoped_site_with_summary_counts(): void
    {
        $identity = $this->createGraph();

        $response = $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_site_03_success')
            ->getJson('/api/v1/sites/'.$identity['site_id']);

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_site_03_success')
            ->assertJsonPath('data.site.site_id', $identity['site_id'])
            ->assertJsonPath('data.site.organization_id', $identity['organization_id'])
            ->assertJsonPath('data.site.center_point.type', 'Point')
            ->assertJsonPath('data.site.center_point.coordinates.0', 123.9)
            ->assertJsonPath('data.site.center_point.coordinates.1', 10.2)
            ->assertJsonPath('data.counts', [
                'boundaries' => 0,
                'plots' => 0,
                'access_permissions' => 0,
                'missions' => 0,
            ])
            ->assertJsonPath('meta.request_id', 'req_site_03_success');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [SITE-03] Foreign, missing, deleted, and malformed identifiers share one anti-enumeration 404.
    public function test_it_hides_unavailable_site_identifiers(): void
    {
        $identity = $this->createGraph();

        foreach ([$identity['foreign_site_id'], $identity['deleted_site_id'], (string) Str::uuid()] as $siteId) {
            $this->withToken($identity['token'])
                ->getJson('/api/v1/sites/'.$siteId)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }

        $this->withToken($identity['token'])
            ->getJson('/api/v1/sites/not-a-uuid')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // [SITE-03] Authentication is mandatory.
    public function test_it_enforces_authentication(): void
    {
        $identity = $this->createGraph();

        $this->getJson('/api/v1/sites/'.$identity['site_id'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    }

    // [SITE-03] A foreign-tenant sites.read grant cannot authorize detail access.
    public function test_it_rejects_a_foreign_tenant_permission_grant(): void
    {
        $foreignGrant = $this->createGraph(localPermission: false, foreignPermission: true);

        $this->withToken($foreignGrant['token'])
            ->getJson('/api/v1/sites/'.$foreignGrant['site_id'])
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'sites.read');
    }

    // [SITE-03] Inactive identities cannot inspect site details.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $identity = $this->createGraph();
        DB::table('organizations')
            ->where('organization_id', $identity['organization_id'])
            ->update(['status' => 'inactive']);

        $this->withToken($identity['token'])
            ->getJson('/api/v1/sites/'.$identity['site_id'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [SITE-03] Detail reads consume the shared authenticated request budget.
    public function test_it_rate_limits_site_detail_requests(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createGraph();

        $this->withToken($identity['token'])
            ->getJson('/api/v1/sites/'.$identity['site_id'])
            ->assertOk();

        $this->withToken($identity['token'])
            ->getJson('/api/v1/sites/'.$identity['site_id'])
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    /**
     * @return array{
     *   organization_id: string,
     *   site_id: string,
     *   foreign_site_id: string,
     *   deleted_site_id: string,
     *   token: string
     * }
     */
    private function createGraph(bool $localPermission = true, bool $foreignPermission = false): array
    {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $permissionId = (string) Str::uuid();
        $siteId = (string) Str::uuid();
        $foreignSiteId = (string) Str::uuid();
        $deletedSiteId = (string) Str::uuid();

        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => 'Current', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => 'Foreign', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, 'detail@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, 'foreign-detail@example.test');

        DB::table('roles')->insert([
            ['role_id' => $localRoleId, 'organization_id' => $organizationId, 'role_name' => 'Reader', 'role_code' => 'reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => 'Foreign Reader', 'role_code' => 'foreign_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert([
            'permission_id' => $permissionId,
            'permission_code' => 'sites.read',
            'permission_name' => 'Read sites',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($localPermission || $foreignPermission) {
            $roleId = $foreignPermission ? $foreignRoleId : $localRoleId;
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->insertSite($siteId, $organizationId, $actorId, 'Current Site', 'DETAIL-CURRENT');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignUserId, 'Foreign Site', 'DETAIL-FOREIGN');
        $this->insertSite($deletedSiteId, $organizationId, $actorId, 'Deleted Site', 'DETAIL-DELETED', deleted: true);
        $geoJson = json_encode(['type' => 'Point', 'coordinates' => [123.9, 10.2]], JSON_THROW_ON_ERROR);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('UPDATE survey_sites SET center_point = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) WHERE site_id = ?', [$geoJson, $siteId]);
        } else {
            DB::table('survey_sites')->where('site_id', $siteId)->update(['center_point' => $geoJson]);
        }

        return [
            'organization_id' => $organizationId,
            'site_id' => $siteId,
            'foreign_site_id' => $foreignSiteId,
            'deleted_site_id' => $deletedSiteId,
            'token' => User::query()->findOrFail($actorId)->createToken('Site detail test', ['*'], now()->addHour())->plainTextToken,
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

    private function insertSite(string $siteId, string $organizationId, string $creatorId, string $name, string $code, bool $deleted = false): void
    {
        DB::table('survey_sites')->insert([
            'site_id' => $siteId,
            'organization_id' => $organizationId,
            'site_name' => $name,
            'site_code' => $code,
            'province' => 'Negros Oriental',
            'city_municipality' => 'Dumaguete City',
            'environment_type' => 'estuarine',
            'status' => 'active',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }
}
