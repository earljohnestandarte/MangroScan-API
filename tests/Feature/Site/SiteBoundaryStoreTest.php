<?php

namespace Tests\Feature\Site;

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

class SiteBoundaryStoreTest extends TestCase
{
    use RefreshDatabase;

    // [BOUND-02] A manager creates one normalized tenant-owned PostGIS polygon and audit.
    public function test_it_creates_a_boundary_with_geojson_and_audit_evidence(): void
    {
        $graph = $this->createGraph();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$graph['token'],
            'X-Request-ID' => 'req_bound_02_success',
            'User-Agent' => 'MangroScan Boundary Test',
        ])->postJson('/api/v1/sites/'.$graph['site_id'].'/boundaries', $this->validPayload());

        $response
            ->assertCreated()
            ->assertHeader('X-Request-ID', 'req_bound_02_success')
            ->assertJsonPath('data.site_id', $graph['site_id'])
            ->assertJsonPath('data.boundary_name', 'Main Survey Area')
            ->assertJsonPath('data.boundary_type', 'survey_area')
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.created_by', $graph['actor_id'])
            ->assertJsonPath('data.boundary_geom.type', 'Polygon')
            ->assertJsonPath('data.boundary_geom.coordinates.0.0.0', 123.8)
            ->assertJsonPath('meta.request_id', 'req_bound_02_success');

        $boundaryId = $response->json('data.boundary_id');
        $this->assertDatabaseHas('site_boundaries', [
            'boundary_id' => $boundaryId,
            'site_id' => $graph['site_id'],
            'boundary_type' => 'survey_area',
        ]);

        $audit = AuditLog::query()->sole();
        $this->assertSame('boundary.create', $audit->action);
        $this->assertSame('site_boundaries', $audit->table_name);
        $this->assertSame($boundaryId, $audit->record_id);
        $this->assertSame($graph['actor_id'], $audit->user_id);
        $this->assertSame('survey_area', $audit->new_values['boundary_type']);
        $this->assertSame('Polygon', $audit->new_values['boundary_geom']['type']);
        $this->assertSame('req_bound_02_success', $audit->request_id);

    }

    // [BOUND-02] Polygon structure, coordinate bounds, enums, and required fields are validated.
    public function test_it_validates_boundary_creation_input(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_bound_02_validation')
            ->postJson('/api/v1/sites/'.$graph['site_id'].'/boundaries', [
                'boundary_name' => ' ',
                'boundary_type' => 'unknown',
                'source' => 'unknown',
                'boundary_geom' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[181, 10], [124, 10], [124, 11], [123, 11]]],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.request_id', 'req_bound_02_validation')
            ->assertJsonValidationErrors(
                ['boundary_name', 'boundary_type', 'source', 'boundary_geom'],
                'error.details',
            );

        $this->assertDatabaseCount('site_boundaries', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [BOUND-02] A structurally closed but self-intersecting polygon is rejected.
    public function test_it_rejects_a_self_intersecting_polygon(): void
    {
        $graph = $this->createGraph();
        $payload = $this->validPayload();
        $payload['boundary_geom']['coordinates'] = [[
            [123.8, 10.1],
            [124.0, 10.3],
            [123.8, 10.3],
            [124.0, 10.1],
            [123.8, 10.1],
        ]];

        $this->withToken($graph['token'])
            ->postJson('/api/v1/sites/'.$graph['site_id'].'/boundaries', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['boundary_geom'], 'error.details');

        $this->assertDatabaseCount('site_boundaries', 0);
    }

    // [BOUND-02] Foreign, missing, and malformed site targets are hidden.
    public function test_it_hides_unavailable_parent_sites(): void
    {
        $graph = $this->createGraph();

        foreach ([$graph['foreign_site_id'], (string) Str::uuid()] as $siteId) {
            $this->withToken($graph['token'])
                ->postJson('/api/v1/sites/'.$siteId.'/boundaries', $this->validPayload())
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }

        $this->withToken($graph['token'])
            ->postJson('/api/v1/sites/not-a-uuid/boundaries', $this->validPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('site_boundaries', 0);
    }

    // [BOUND-02] Mandatory audit failure rolls back the polygon insertion.
    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $graph = $this->createGraph();
        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $auditLogger);

        $this->withToken($graph['token'])
            ->postJson('/api/v1/sites/'.$graph['site_id'].'/boundaries', $this->validPayload())
            ->assertInternalServerError();

        $this->assertDatabaseCount('site_boundaries', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [BOUND-02] Authentication and boundaries.manage are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->postJson('/api/v1/sites/'.Str::uuid().'/boundaries', $this->validPayload())
            ->assertUnauthorized();

        $graph = $this->createGraph(localPermission: false);
        $this->withToken($graph['token'])
            ->postJson('/api/v1/sites/'.$graph['site_id'].'/boundaries', $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'boundaries.manage');
    }

    // [BOUND-02] A foreign-role permission assignment cannot authorize creation.
    public function test_it_rejects_a_foreign_tenant_permission_grant(): void
    {
        $graph = $this->createGraph(localPermission: false, foreignPermission: true);

        $this->withToken($graph['token'])
            ->postJson('/api/v1/sites/'.$graph['site_id'].'/boundaries', $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'boundaries.manage');
    }

    // [BOUND-02] Throttling prevents a second polygon and audit.
    public function test_it_rate_limits_boundary_creation(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->postJson('/api/v1/sites/'.$graph['site_id'].'/boundaries', $this->validPayload())
            ->assertCreated();

        $payload = $this->validPayload();
        $payload['boundary_name'] = 'Second Area';
        $this->withToken($graph['token'])
            ->postJson('/api/v1/sites/'.$graph['site_id'].'/boundaries', $payload)
            ->assertTooManyRequests();

        $this->assertDatabaseCount('site_boundaries', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'boundary_name' => ' Main Survey Area ',
            'boundary_type' => ' SURVEY_AREA ',
            'source' => ' MANUAL ',
            'boundary_geom' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    ['123.8', '10.1'],
                    [124.0, 10.1],
                    [124.0, 10.3],
                    [123.8, 10.1],
                ]],
            ],
        ];
    }

    /** @return array{actor_id: string, site_id: string, foreign_site_id: string, token: string} */
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

        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => 'Current', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => 'Foreign', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, 'boundary-manager@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, 'foreign-boundary-manager@example.test');
        DB::table('roles')->insert([
            ['role_id' => $roleId, 'organization_id' => $organizationId, 'role_name' => 'Boundary Manager', 'role_code' => 'boundary_manager', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => 'Foreign Boundary Manager', 'role_code' => 'foreign_boundary_manager', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert(['permission_id' => $permissionId, 'permission_code' => 'boundaries.manage', 'permission_name' => 'Manage boundaries', 'created_at' => now(), 'updated_at' => now()]);

        if ($localPermission || $foreignPermission) {
            $assignedRole = $foreignPermission ? $foreignRoleId : $roleId;
            DB::table('role_permissions')->insert(['role_id' => $assignedRole, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $assignedRole, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->insertSite($siteId, $organizationId, $actorId, 'BOUND-CREATE');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignUserId, 'BOUND-FOREIGN-CREATE');

        return [
            'actor_id' => $actorId,
            'site_id' => $siteId,
            'foreign_site_id' => $foreignSiteId,
            'token' => User::query()->findOrFail($actorId)->createToken('Boundary creation test', ['*'], now()->addHour())->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organizationId, 'first_name' => 'Boundary', 'last_name' => 'Manager', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertSite(string $id, string $organizationId, string $creatorId, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organizationId, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now()]);
    }
}
