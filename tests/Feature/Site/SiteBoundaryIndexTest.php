<?php

namespace Tests\Feature\Site;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteBoundaryIndexTest extends TestCase
{
    use RefreshDatabase;

    // [BOUND-01] Boundaries are ordered, tenant-parent scoped, and projected as GeoJSON.
    public function test_it_lists_site_boundaries_and_updates_the_site_summary(): void
    {
        $graph = $this->createGraph();

        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_bound_01_success')
            ->getJson('/api/v1/sites/'.$graph['site_id'].'/boundaries');

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_bound_01_success')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.boundary_id', $graph['alpha_boundary_id'])
            ->assertJsonPath('data.0.site_id', $graph['site_id'])
            ->assertJsonPath('data.0.boundary_geom.type', 'Polygon')
            ->assertJsonPath('data.0.boundary_geom.coordinates.0.0.0', 123.8)
            ->assertJsonPath('data.1.boundary_id', $graph['beta_boundary_id'])
            ->assertJsonPath('meta.request_id', 'req_bound_01_success');

        $this->withToken($graph['token'])
            ->getJson('/api/v1/sites/'.$graph['site_id'])
            ->assertOk()
            ->assertJsonPath('data.counts.boundaries', 2);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [BOUND-01] Foreign, missing, and malformed site IDs reveal no boundary data.
    public function test_it_hides_unavailable_parent_sites(): void
    {
        $graph = $this->createGraph();

        foreach ([$graph['foreign_site_id'], (string) Str::uuid()] as $siteId) {
            $this->withToken($graph['token'])
                ->getJson('/api/v1/sites/'.$siteId.'/boundaries')
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }

        $this->withToken($graph['token'])
            ->getJson('/api/v1/sites/not-a-uuid/boundaries')
            ->assertNotFound();
    }

    // [BOUND-01] Authentication and a current-tenant sites.read grant are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $graph = $this->createGraph(localPermission: false);

        $this->getJson('/api/v1/sites/'.$graph['site_id'].'/boundaries')
            ->assertUnauthorized();

        $this->withToken($graph['token'])
            ->getJson('/api/v1/sites/'.$graph['site_id'].'/boundaries')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'sites.read');
    }

    // [BOUND-01] A foreign role cannot grant boundary-list access.
    public function test_it_rejects_a_foreign_tenant_permission_grant(): void
    {
        $graph = $this->createGraph(localPermission: false, foreignPermission: true);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/sites/'.$graph['site_id'].'/boundaries')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'sites.read');
    }

    // [BOUND-01] Boundary reads share the authenticated throttle budget.
    public function test_it_rate_limits_boundary_lists(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->getJson('/api/v1/sites/'.$graph['site_id'].'/boundaries')
            ->assertOk();

        $this->withToken($graph['token'])
            ->getJson('/api/v1/sites/'.$graph['site_id'].'/boundaries')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    /**
     * @return array{site_id: string, foreign_site_id: string, alpha_boundary_id: string, beta_boundary_id: string, token: string}
     */
    private function createGraph(bool $localPermission = true, bool $foreignPermission = false): array
    {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        $roleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $permissionId = (string) Str::uuid();
        $siteId = (string) Str::uuid();
        $foreignSiteId = (string) Str::uuid();
        $alphaBoundaryId = (string) Str::uuid();
        $betaBoundaryId = (string) Str::uuid();

        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => 'Current', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => 'Foreign', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, 'boundary@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, 'foreign-boundary@example.test');
        DB::table('roles')->insert([
            ['role_id' => $roleId, 'organization_id' => $organizationId, 'role_name' => 'Reader', 'role_code' => 'reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => 'Foreign Reader', 'role_code' => 'foreign_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert(['permission_id' => $permissionId, 'permission_code' => 'sites.read', 'permission_name' => 'Read sites', 'created_at' => now(), 'updated_at' => now()]);

        if ($localPermission || $foreignPermission) {
            $assignedRole = $foreignPermission ? $foreignRoleId : $roleId;
            DB::table('role_permissions')->insert(['role_id' => $assignedRole, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $assignedRole, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->insertSite($siteId, $organizationId, $actorId, 'BOUND-SITE');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignUserId, 'BOUND-FOREIGN');
        $polygon = ['type' => 'Polygon', 'coordinates' => [[[123.8, 10.1], [124.0, 10.1], [124.0, 10.3], [123.8, 10.1]]]];
        $this->insertBoundary($betaBoundaryId, $siteId, $actorId, 'Beta Zone', $polygon);
        $this->insertBoundary($alphaBoundaryId, $siteId, $actorId, 'Alpha Zone', $polygon);
        $this->insertBoundary((string) Str::uuid(), $foreignSiteId, $foreignUserId, 'Foreign Zone', $polygon);

        return [
            'site_id' => $siteId,
            'foreign_site_id' => $foreignSiteId,
            'alpha_boundary_id' => $alphaBoundaryId,
            'beta_boundary_id' => $betaBoundaryId,
            'token' => User::query()->findOrFail($actorId)->createToken('Boundary list test', ['*'], now()->addHour())->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organizationId, 'first_name' => 'Boundary', 'last_name' => 'Reader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertSite(string $id, string $organizationId, string $creatorId, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organizationId, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $polygon */
    private function insertBoundary(string $id, string $siteId, string $creatorId, string $name, array $polygon): void
    {
        $geoJson = json_encode($polygon, JSON_THROW_ON_ERROR);
        $values = [$id, $siteId, $name, 'survey_area', $geoJson, 'manual', $creatorId, now(), now()];

        if (DB::getDriverName() === 'pgsql') {
            DB::insert('INSERT INTO site_boundaries (boundary_id, site_id, boundary_name, boundary_type, boundary_geom, source, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?, ?, ?, ?)', $values);
        } else {
            DB::table('site_boundaries')->insert(array_combine(['boundary_id', 'site_id', 'boundary_name', 'boundary_type', 'boundary_geom', 'source', 'created_by', 'created_at', 'updated_at'], $values));
        }
    }
}
