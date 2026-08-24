<?php

namespace Tests\Feature\Validation;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ValidationSessionStoreTest extends TestCase
{
    use RefreshDatabase;

    // [VAL-03] Creation persists one normalized open session and mandatory audit evidence.
    public function test_it_creates_a_validation_session_with_exact_safe_fields(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeaders(['X-Request-ID' => 'req_val_03', 'User-Agent' => 'Validation Store Test'])
            ->postJson('/api/v1/validation-sessions', $this->payload($graph));

        $response->assertCreated()->assertHeader('X-Request-ID', 'req_val_03')
            ->assertJsonCount(1)
            ->assertJsonPath('data.mission_id', $graph['mission_id'])
            ->assertJsonPath('data.site_id', $graph['site_id'])
            ->assertJsonPath('data.plot_id', $graph['plot_id'])
            ->assertJsonPath('data.validated_by', $graph['actor_id'])
            ->assertJsonPath('data.validation_date', '2026-08-24')
            ->assertJsonPath('data.method', 'ground_survey')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.notes', 'Field evidence.')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.completed_by', null);

        $this->assertSame([
            'validation_session_id', 'mission_id', 'site_id', 'plot_id', 'validated_by',
            'validation_date', 'method', 'status', 'notes', 'completed_at', 'completed_by',
            'created_at', 'updated_at',
        ], array_keys($response->json('data')));
        $sessionId = $response->json('data.validation_session_id');
        $this->assertDatabaseHas('validation_sessions', [
            'validation_session_id' => $sessionId,
            'mission_id' => $graph['mission_id'],
            'status' => 'open',
        ]);
        $audit = AuditLog::query()->sole();
        $this->assertSame('validation.create', $audit->action);
        $this->assertSame('validation_sessions', $audit->table_name);
        $this->assertSame($sessionId, $audit->record_id);
        $this->assertSame($graph['actor_id'], $audit->user_id);
        $this->assertSame($graph['mission_id'], $audit->new_values['mission_id']);
        $this->assertSame('req_val_03', $audit->request_id);
    }

    // [VAL-03] Nullable plot and notes remain nullable without inventing values.
    public function test_it_creates_a_site_level_session_with_nullable_fields(): void
    {
        $graph = $this->createGraph(prefix: 'nullable-');
        $payload = $this->payload($graph);
        unset($payload['plot_id']);
        $payload['method'] = 'expert_review';
        $payload['notes'] = '   ';

        $this->withToken($graph['token'])->postJson('/api/v1/validation-sessions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.plot_id', null)
            ->assertJsonPath('data.notes', null)
            ->assertJsonPath('data.method', 'expert_review');
    }

    // [VAL-03] Foreign, missing and inconsistent lineage targets are non-enumerable.
    public function test_it_hides_unavailable_lineage_targets(): void
    {
        $graph = $this->createGraph();
        $changes = [
            ['site_id' => $graph['foreign_site_id'], 'mission_id' => $graph['foreign_mission_id']],
            ['site_id' => (string) Str::uuid()],
            ['mission_id' => $graph['foreign_mission_id']],
            ['mission_id' => (string) Str::uuid()],
            ['plot_id' => $graph['foreign_plot_id']],
            ['plot_id' => (string) Str::uuid()],
        ];

        foreach ($changes as $change) {
            $this->withToken($graph['token'])
                ->postJson('/api/v1/validation-sessions', array_replace($this->payload($graph), $change))
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $this->assertDatabaseCount('validation_sessions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [VAL-03] The selected validator must be active, tenant-local and effectively authorized.
    public function test_it_hides_ineligible_validators(): void
    {
        $graph = $this->createGraph();

        foreach ([$graph['foreign_user_id'], $graph['inactive_user_id'], $graph['unqualified_user_id'], (string) Str::uuid()] as $validator) {
            $payload = $this->payload($graph);
            $payload['validated_by'] = $validator;
            $this->withToken($graph['token'])->postJson('/api/v1/validation-sessions', $payload)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $this->assertDatabaseCount('validation_sessions', 0);
    }

    // [VAL-03] Repeating the same open assignment returns a workflow conflict.
    public function test_it_rejects_an_equivalent_open_session(): void
    {
        $graph = $this->createGraph();
        $this->withToken($graph['token'])->postJson('/api/v1/validation-sessions', $this->payload($graph))->assertCreated();

        $this->withToken($graph['token'])->postJson('/api/v1/validation-sessions', $this->payload($graph))
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        $this->assertDatabaseCount('validation_sessions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [VAL-03] Invalid fields fail together before target resolution.
    public function test_it_validates_the_creation_contract(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->postJson('/api/v1/validation-sessions', [
            'mission_id' => 'bad',
            'site_id' => 'bad',
            'plot_id' => 'bad',
            'validated_by' => 'bad',
            'validation_date' => '24-08-2026',
            'method' => 'desk_review',
            'notes' => str_repeat('x', 5001),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors([
                'mission_id', 'site_id', 'plot_id', 'validated_by', 'validation_date', 'method', 'notes',
            ], 'error.details');
        $this->assertDatabaseCount('validation_sessions', 0);
    }

    // [VAL-03] Audit failure rolls the session back atomically.
    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $graph = $this->createGraph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($graph['token'])->postJson('/api/v1/validation-sessions', $this->payload($graph))
            ->assertInternalServerError();
        $this->assertDatabaseCount('validation_sessions', 0);
    }

    // [VAL-03] Authentication and a current-tenant validation.create grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $this->postJson('/api/v1/validation-sessions', [])->assertUnauthorized();

        $missing = $this->createGraph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->postJson('/api/v1/validation-sessions', $this->payload($missing))
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'validation.create');

        $foreign = $this->createGraph(permission: false, foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->postJson('/api/v1/validation-sessions', $this->payload($foreign))
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'validation.create');
    }

    // [VAL-03] Inactive actors cannot create validation work.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->createGraph(prefix: 'inactive-actor-');
        DB::table('users')->where('user_id', $graph['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($graph['token'])->postJson('/api/v1/validation-sessions', $this->payload($graph))
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [VAL-03] Throttling prevents a second session and audit event.
    public function test_it_rate_limits_validation_session_creation(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $this->withToken($graph['token'])->postJson('/api/v1/validation-sessions', $this->payload($graph))->assertCreated();
        $payload = $this->payload($graph);
        $payload['validation_date'] = '2026-08-25';
        $this->withToken($graph['token'])->postJson('/api/v1/validation-sessions', $payload)->assertTooManyRequests();
        $this->assertDatabaseCount('validation_sessions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [VAL-03] The route and column-limited INSERT privilege are versioned.
    public function test_it_registers_the_route_and_versions_insert_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/validation-sessions'
            && in_array('POST', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:validation.create', $route->gatherMiddleware());
        $this->assertContains('throttle:auth.authenticated', $route->gatherMiddleware());

        $dcl = file_get_contents(database_path('sql/dcl/048_validation_session_creation_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT INSERT (', $dcl);
        $this->assertStringContainsString('ON TABLE app.validation_sessions TO mangroscan_api_rw;', $dcl);
        foreach (['status', 'completed_at', 'completed_by', 'UPDATE', 'DELETE', 'mangroscan_worker', 'mangroscan_auditor'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    /** @param array<string, string> $graph @return array<string, mixed> */
    private function payload(array $graph): array
    {
        return [
            'mission_id' => strtoupper($graph['mission_id']),
            'site_id' => strtoupper($graph['site_id']),
            'plot_id' => strtoupper($graph['plot_id']),
            'validated_by' => strtoupper($graph['actor_id']),
            'validation_date' => '2026-08-24',
            'method' => ' GROUND_SURVEY ',
            'notes' => ' Field evidence. ',
        ];
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
            'inactive_user_id' => (string) Str::uuid(),
            'unqualified_user_id' => (string) Str::uuid(),
            'site_id' => (string) Str::uuid(),
            'foreign_site_id' => (string) Str::uuid(),
            'mission_id' => (string) Str::uuid(),
            'foreign_mission_id' => (string) Str::uuid(),
            'plot_id' => (string) Str::uuid(),
            'foreign_plot_id' => (string) Str::uuid(),
        ];

        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization_id'], 'organization_name' => $prefix.'Validation Create Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $ids['foreign_organization_id'], 'organization_name' => $prefix.'Foreign Validation Create Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($ids['actor_id'], $ids['organization_id'], 'active', $prefix.'validation-creator@example.test');
        $this->user($ids['foreign_user_id'], $ids['foreign_organization_id'], 'active', $prefix.'foreign-validation-creator@example.test');
        $this->user($ids['inactive_user_id'], $ids['organization_id'], 'inactive', $prefix.'inactive-validation-creator@example.test');
        $this->user($ids['unqualified_user_id'], $ids['organization_id'], 'active', $prefix.'unqualified-validation-creator@example.test');

        $localRole = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRole, 'organization_id' => $ids['organization_id'], 'role_name' => $prefix.'Validation Creator', 'role_code' => $prefix.'validation_creator', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $ids['foreign_organization_id'], 'role_name' => $prefix.'Foreign Validation Creator', 'role_code' => $prefix.'foreign_validation_creator', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'validation.create')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore([
            'permission_id' => $permissionId, 'permission_code' => 'validation.create',
            'permission_name' => 'Create validation sessions', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('role_permissions')->insert(['role_id' => $foreignRole, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['user_id' => $ids['foreign_user_id'], 'role_id' => $foreignRole, 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $localRole, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert([
                ['user_id' => $ids['actor_id'], 'role_id' => $localRole, 'created_at' => now(), 'updated_at' => now()],
                ['user_id' => $ids['inactive_user_id'], 'role_id' => $localRole, 'created_at' => now(), 'updated_at' => now()],
            ]);
        } elseif ($foreignPermission) {
            DB::table('user_roles')->insert(['user_id' => $ids['actor_id'], 'role_id' => $foreignRole, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->site($ids['site_id'], $ids['organization_id'], $ids['actor_id'], $prefix.'VAL-CREATE-SITE');
        $this->site($ids['foreign_site_id'], $ids['foreign_organization_id'], $ids['foreign_user_id'], $prefix.'FOREIGN-VAL-CREATE-SITE');
        $this->mission($ids['mission_id'], $ids['site_id'], $ids['actor_id'], $prefix.'VAL-CREATE-MISSION');
        $this->mission($ids['foreign_mission_id'], $ids['foreign_site_id'], $ids['foreign_user_id'], $prefix.'FOREIGN-VAL-CREATE-MISSION');
        $this->plot($ids['plot_id'], $ids['site_id'], $prefix.'VAL-CREATE-PLOT');
        $this->plot($ids['foreign_plot_id'], $ids['foreign_site_id'], $prefix.'FOREIGN-VAL-CREATE-PLOT');

        return $ids + [
            'token' => User::query()->findOrFail($ids['actor_id'])->createToken($prefix.'validation-session-store')->plainTextToken,
        ];
    }

    private function user(string $id, string $organizationId, string $status, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId,
            'first_name' => 'Validation', 'last_name' => 'Creator', 'email' => $email,
            'password' => Hash::make('password'), 'status' => $status,
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

    private function mission(string $id, string $siteId, string $actorId, string $code): void
    {
        DB::table('survey_missions')->insert([
            'mission_id' => $id, 'site_id' => $siteId, 'mission_code' => $code,
            'mission_title' => $code, 'mission_objective' => 'Validate field evidence.',
            'mission_status' => 'completed', 'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
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
}
