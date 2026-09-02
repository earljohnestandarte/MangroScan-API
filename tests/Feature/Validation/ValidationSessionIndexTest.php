<?php

namespace Tests\Feature\Validation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ValidationSessionIndexTest extends TestCase
{
    use RefreshDatabase;

    // [VAL-02] Tenant sessions use the exact safe resource and fixed-page envelope.
    public function test_it_lists_tenant_validation_sessions_with_exact_safe_fields(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_val_02')
            ->getJson('/api/v1/validation-sessions?page=1');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_val_02')
            ->assertJsonPath('meta', [
                'request_id' => 'req_val_02',
                'page' => 1,
                'per_page' => 25,
                'total' => 2,
                'last_page' => 1,
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.validation_session_id', $graph['latest_session_id'])
            ->assertJsonPath('data.0.validation_date', '2026-08-24')
            ->assertJsonPath('data.0.status', 'open')
            ->assertJsonPath('data.1.validation_session_id', $graph['older_session_id']);

        $this->assertSame([
            'validation_session_id', 'mission_id', 'site_id', 'plot_id', 'validated_by',
            'validation_date', 'method', 'status', 'notes', 'completed_at', 'completed_by',
            'created_at', 'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertNotContains($graph['foreign_session_id'], $response->json('data.*.validation_session_id'));
        $this->assertNotContains($graph['inconsistent_site_session_id'], $response->json('data.*.validation_session_id'));
        $this->assertNotContains($graph['inconsistent_plot_session_id'], $response->json('data.*.validation_session_id'));
        $this->assertNotContains($graph['deleted_mission_session_id'], $response->json('data.*.validation_session_id'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [VAL-02] Mission, site and status filters compose after normalization.
    public function test_it_filters_tenant_validation_sessions(): void
    {
        $graph = $this->createGraph();
        $query = http_build_query([
            'mission_id' => strtoupper($graph['mission_id']),
            'site_id' => strtoupper($graph['site_id']),
            'status' => ' OPEN ',
        ]);

        $this->withToken($graph['token'])->getJson('/api/v1/validation-sessions?'.$query)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.validation_session_id', $graph['latest_session_id']);
    }

    // [VAL-02] Foreign and missing tenant filter targets remain non-enumerable.
    public function test_it_hides_unavailable_filter_targets(): void
    {
        $graph = $this->createGraph();

        foreach ([
            'site_id='.$graph['foreign_site_id'],
            'mission_id='.$graph['foreign_mission_id'],
            'site_id='.(string) Str::uuid(),
            'mission_id='.(string) Str::uuid(),
        ] as $query) {
            $this->withToken($graph['token'])->getJson('/api/v1/validation-sessions?'.$query)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    // [VAL-02] Invalid filters fail before scoped target resolution.
    public function test_it_validates_validation_session_filters(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->getJson('/api/v1/validation-sessions?mission_id=bad&site_id=bad&status=pending&page=0')
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['mission_id', 'site_id', 'status', 'page'], 'error.details');
    }

    // [VAL-02] Authentication and a current-tenant validation.read grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $this->getJson('/api/v1/validation-sessions')->assertUnauthorized();

        $missing = $this->createGraph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->getJson('/api/v1/validation-sessions')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'validation.read');

        $foreign = $this->createGraph(foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->getJson('/api/v1/validation-sessions')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'validation.read');
    }

    // [VAL-02] Inactive identities cannot inspect validation sessions.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($graph['token'])->getJson('/api/v1/validation-sessions')->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [VAL-02] Validation session reads share the authenticated request budget.
    public function test_it_rate_limits_validation_session_lists(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->getJson('/api/v1/validation-sessions')->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/validation-sessions')
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [VAL-02] The route and existing SELECT privilege are explicitly versioned.
    public function test_it_registers_the_route_and_reuses_least_privilege_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/validation-sessions');
        $this->assertNotNull($route);
        $this->assertContains('GET', $route->methods());
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:validation.read', $route->gatherMiddleware());
        $this->assertContains('throttle:auth.authenticated', $route->gatherMiddleware());

        $foundation = file_get_contents(database_path('sql/dcl/045_validation_foundation_grants.sql'));
        $workflow = file_get_contents(database_path('sql/dcl/046_jason_workflow_grants.sql'));
        $this->assertIsString($foundation);
        $this->assertIsString($workflow);
        $this->assertStringContainsString('app.validation_sessions', $foundation);
        $this->assertStringContainsString('ON TABLE app.validation_sessions TO mangroscan_api_rw;', $workflow);
        $this->assertStringNotContainsString('INSERT ON TABLE app.validation_sessions', $workflow);
        $this->assertStringNotContainsString('DELETE ON TABLE app.validation_sessions', $workflow);
    }

    /** @return array<string, string> */
    private function createGraph(
        bool $permission = true,
        bool $foreignPermission = false,
        string $prefix = '',
    ): array {
        $ids = [
            'organization_id' => (string) Str::uuid(),
            'foreign_organization_id' => (string) Str::uuid(),
            'actor_id' => (string) Str::uuid(),
            'foreign_user_id' => (string) Str::uuid(),
            'site_id' => (string) Str::uuid(),
            'foreign_site_id' => (string) Str::uuid(),
            'mission_id' => (string) Str::uuid(),
            'foreign_mission_id' => (string) Str::uuid(),
            'deleted_mission_id' => (string) Str::uuid(),
            'plot_id' => (string) Str::uuid(),
            'foreign_plot_id' => (string) Str::uuid(),
            'latest_session_id' => (string) Str::uuid(),
            'older_session_id' => (string) Str::uuid(),
            'foreign_session_id' => (string) Str::uuid(),
            'inconsistent_site_session_id' => (string) Str::uuid(),
            'inconsistent_plot_session_id' => (string) Str::uuid(),
            'deleted_mission_session_id' => (string) Str::uuid(),
        ];

        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization_id'], 'organization_name' => $prefix.'Validation Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $ids['foreign_organization_id'], 'organization_name' => $prefix.'Foreign Validation Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($ids['actor_id'], $ids['organization_id'], $prefix.'validation-reader@example.test');
        $this->user($ids['foreign_user_id'], $ids['foreign_organization_id'], $prefix.'foreign-validation-reader@example.test');

        $localRole = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRole, 'organization_id' => $ids['organization_id'], 'role_name' => $prefix.'Validation Reader', 'role_code' => $prefix.'validation_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $ids['foreign_organization_id'], 'role_name' => $prefix.'Foreign Validation Reader', 'role_code' => $prefix.'foreign_validation_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'validation.read')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore([
            'permission_id' => $permissionId, 'permission_code' => 'validation.read',
            'permission_name' => 'Read validation data', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($permission || $foreignPermission) {
            $role = $foreignPermission ? $foreignRole : $localRole;
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $ids['actor_id'], 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->site($ids['site_id'], $ids['organization_id'], $ids['actor_id'], $prefix.'VAL-SITE');
        $this->site($ids['foreign_site_id'], $ids['foreign_organization_id'], $ids['foreign_user_id'], $prefix.'FOREIGN-VAL-SITE');
        $this->mission($ids['mission_id'], $ids['site_id'], $ids['actor_id'], $prefix.'VAL-MISSION');
        $this->mission($ids['foreign_mission_id'], $ids['foreign_site_id'], $ids['foreign_user_id'], $prefix.'FOREIGN-VAL-MISSION');
        $this->mission($ids['deleted_mission_id'], $ids['site_id'], $ids['actor_id'], $prefix.'DELETED-VAL-MISSION', true);
        $this->plot($ids['plot_id'], $ids['site_id'], $prefix.'VAL-PLOT');
        $this->plot($ids['foreign_plot_id'], $ids['foreign_site_id'], $prefix.'FOREIGN-VAL-PLOT');

        $this->validationSession($ids['latest_session_id'], $ids['mission_id'], $ids['site_id'], $ids['plot_id'], $ids['actor_id'], '2026-08-24', 'open');
        $this->validationSession($ids['older_session_id'], $ids['mission_id'], $ids['site_id'], null, $ids['actor_id'], '2026-08-23', 'completed');
        $this->validationSession($ids['foreign_session_id'], $ids['foreign_mission_id'], $ids['foreign_site_id'], $ids['foreign_plot_id'], $ids['foreign_user_id'], '2026-08-25', 'open');
        $this->validationSession($ids['inconsistent_site_session_id'], $ids['mission_id'], $ids['foreign_site_id'], null, $ids['actor_id'], '2026-08-26', 'open');
        $this->validationSession($ids['inconsistent_plot_session_id'], $ids['mission_id'], $ids['site_id'], $ids['foreign_plot_id'], $ids['actor_id'], '2026-08-27', 'open');
        $this->validationSession($ids['deleted_mission_session_id'], $ids['deleted_mission_id'], $ids['site_id'], null, $ids['actor_id'], '2026-08-28', 'open');

        return $ids + [
            'token' => User::query()->findOrFail($ids['actor_id'])->createToken($prefix.'validation-session-index')->plainTextToken,
        ];
    }

    private function user(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId,
            'first_name' => 'Validation', 'last_name' => 'Reader', 'email' => $email,
            'password' => Hash::make('password'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function site(string $id, string $organizationId, string $actorId, string $code): void
    {
        DB::table('survey_sites')->insert([
            'site_id' => $id, 'organization_id' => $organizationId,
            'site_name' => $code, 'site_code' => $code,
            'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City',
            'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mission(string $id, string $siteId, string $actorId, string $code, bool $deleted = false): void
    {
        DB::table('survey_missions')->insert([
            'mission_id' => $id, 'site_id' => $siteId, 'mission_code' => $code,
            'mission_title' => $code, 'mission_objective' => 'Validate field evidence.',
            'mission_status' => 'completed', 'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null,
        ]);
    }

    private function plot(string $id, string $siteId, string $code): void
    {
        $geometry = json_encode(['type' => 'Polygon', 'coordinates' => [[[123.8, 10.1], [123.9, 10.1], [123.9, 10.2], [123.8, 10.1]]]], JSON_THROW_ON_ERROR);
        $values = [$id, $siteId, $code, $code, $geometry, now(), now()];
        if (DB::getDriverName() === 'pgsql') {
            DB::insert('INSERT INTO monitoring_plots (plot_id, site_id, plot_code, plot_name, plot_geom, created_at, updated_at) VALUES (?, ?, ?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?, ?)', $values);

            return;
        }
        DB::table('monitoring_plots')->insert(array_combine(
            ['plot_id', 'site_id', 'plot_code', 'plot_name', 'plot_geom', 'created_at', 'updated_at'],
            $values,
        ));
    }

    private function validationSession(
        string $id,
        string $missionId,
        string $siteId,
        ?string $plotId,
        string $validatorId,
        string $date,
        string $status,
    ): void {
        DB::table('validation_sessions')->insert([
            'validation_session_id' => $id, 'mission_id' => $missionId, 'site_id' => $siteId,
            'plot_id' => $plotId, 'validated_by' => $validatorId, 'validation_date' => $date,
            'method' => 'ground_survey', 'status' => $status, 'notes' => 'Field evidence.',
            'completed_at' => $status === 'completed' ? '2026-08-23T12:00:00+00:00' : null,
            'completed_by' => $status === 'completed' ? $validatorId : null,
            'created_at' => $date.'T01:00:00+00:00', 'updated_at' => $date.'T01:00:00+00:00',
        ]);
    }
}
