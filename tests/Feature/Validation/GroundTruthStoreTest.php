<?php

namespace Tests\Feature\Validation;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GroundTruthStoreTest extends TestCase
{
    use RefreshDatabase;

    // [GT-01] Creation persists the approved field record, exact safe resource, and audit evidence.
    public function test_it_creates_an_audited_ground_truth_record(): void
    {
        $graph = $this->graph();
        $response = $this->withToken($graph['token'])
            ->withHeaders(['X-Request-ID' => 'req_gt_01', 'User-Agent' => 'Ground Truth Test'])
            ->postJson($this->uri($graph['session']), $this->payload($graph));

        $response->assertCreated()->assertHeader('X-Request-ID', 'req_gt_01')
            ->assertJsonPath('data.validation_session_id', $graph['session'])
            ->assertJsonPath('data.field_code', 'FIELD-001')
            ->assertJsonPath('data.species_id', $graph['species'])
            ->assertJsonPath('data.ground_location.type', 'Point')
            ->assertJsonPath('data.ground_location.coordinates.0', 123.81)
            ->assertJsonPath('data.measured_height_meters', '4.50')
            ->assertJsonPath('data.estimated_age_years', '6.00')
            ->assertJsonPath('data.diameter_cm', '22.25')
            ->assertJsonPath('data.crown_diameter_m', '3.75')
            ->assertJsonPath('data.health_status', 'healthy')
            ->assertJsonPath('data.is_tree', true)
            ->assertJsonPath('data.remarks', 'Verified in the field.')
            ->assertJsonMissingPath('data.photo_path');

        $this->assertSame([
            'ground_truth_id', 'validation_session_id', 'field_code', 'species_id',
            'ground_location', 'measured_height_meters', 'estimated_age_years', 'diameter_cm',
            'crown_diameter_m', 'health_status', 'is_tree', 'remarks', 'created_at',
        ], array_keys($response->json('data')));

        $recordId = $response->json('data.ground_truth_id');
        $this->assertDatabaseHas('ground_truth_tree_records', [
            'ground_truth_id' => $recordId,
            'validation_session_id' => $graph['session'],
            'field_code' => 'FIELD-001',
            'species_id' => $graph['species'],
            'crown_diameter_m' => 3.75,
            'is_tree' => true,
            'photo_path' => 'private/validation/FIELD-001.jpg',
            'remarks' => 'Verified in the field.',
        ]);

        $audit = AuditLog::query()->sole();
        $this->assertSame('ground_truth.create', $audit->action);
        $this->assertSame('ground_truth_tree_records', $audit->table_name);
        $this->assertSame($recordId, $audit->record_id);
        $this->assertSame($graph['actor'], $audit->user_id);
        $this->assertTrue($audit->new_values['has_photo']);
        $this->assertArrayNotHasKey('photo_path', $audit->new_values);
        $this->assertSame('req_gt_01', $audit->request_id);
    }

    // [GT-01] Optional measurements remain null and a non-tree field observation is represented explicitly.
    public function test_it_preserves_nullable_fields_and_false_is_tree(): void
    {
        $graph = $this->graph(prefix: 'nullable-');

        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), [
            'field_code' => '   ',
            'species_id' => null,
            'location' => ['type' => 'Point', 'coordinates' => [123.8, 10.1]],
            'health_status' => ' UNKNOWN ',
            'is_tree' => false,
            'photo_path' => '   ',
            'notes' => '   ',
        ])->assertCreated()
            ->assertJsonPath('data.field_code', null)
            ->assertJsonPath('data.species_id', null)
            ->assertJsonPath('data.measured_height_meters', null)
            ->assertJsonPath('data.crown_diameter_m', null)
            ->assertJsonPath('data.health_status', 'unknown')
            ->assertJsonPath('data.is_tree', false)
            ->assertJsonPath('data.remarks', null);

        $this->assertDatabaseHas('ground_truth_tree_records', [
            'validation_session_id' => $graph['session'], 'is_tree' => false,
            'field_code' => null, 'photo_path' => null, 'remarks' => null,
        ]);
    }

    // [GT-01] Foreign, inconsistent, missing, and malformed sessions are non-enumerable.
    public function test_it_hides_unavailable_validation_sessions(): void
    {
        $graph = $this->graph();

        foreach ([$graph['foreign_session'], $graph['inconsistent_session'], (string) Str::uuid(), 'bad'] as $session) {
            $this->withToken($graph['token'])->postJson($this->uri($session), $this->payload($graph))
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }

        $this->assertDatabaseCount('ground_truth_tree_records', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [GT-01] Completed sessions reject new evidence without a partial write.
    public function test_it_rejects_records_for_a_completed_session(): void
    {
        $graph = $this->graph();
        DB::table('validation_sessions')->where('validation_session_id', $graph['session'])->update([
            'status' => 'completed', 'completed_at' => now(), 'completed_by' => $graph['actor'],
        ]);

        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), $this->payload($graph))
            ->assertConflict()->assertJsonPath('error.details.status', 'completed');
        $this->assertDatabaseCount('ground_truth_tree_records', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [GT-01] Species references must exist and remain active.
    public function test_it_hides_unavailable_species(): void
    {
        $graph = $this->graph();

        foreach ([$graph['inactive_species'], (string) Str::uuid()] as $species) {
            $payload = $this->payload($graph);
            $payload['species_id'] = $species;
            $this->withToken($graph['token'])->postJson($this->uri($graph['session']), $payload)
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }

        $this->assertDatabaseCount('ground_truth_tree_records', 0);
    }

    // [GT-01] The approved body, Point geometry, domains, and numeric precision are strictly validated.
    public function test_it_validates_the_ground_truth_contract(): void
    {
        $graph = $this->graph();

        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), [
            'field_code' => str_repeat('x', 81),
            'species_id' => 'bad',
            'location' => ['type' => 'Polygon', 'coordinates' => [181, -91, 1]],
            'height_m' => -1,
            'age_years' => '1.234',
            'diameter_cm' => 1000000,
            'crown_diameter_m' => -1,
            'health_status' => 'excellent',
            'is_tree' => 'perhaps',
            'photo_path' => str_repeat('p', 2049),
            'notes' => str_repeat('n', 5001),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors([
                'field_code', 'species_id', 'location.type', 'location.coordinates',
                'location.coordinates.0', 'location.coordinates.1', 'height_m', 'age_years',
                'diameter_cm', 'crown_diameter_m', 'health_status', 'is_tree', 'photo_path', 'notes',
            ], 'error.details');

        $this->assertDatabaseCount('ground_truth_tree_records', 0);
    }

    // [GT-01] Audit persistence is part of the same transaction as field evidence.
    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $graph = $this->graph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), $this->payload($graph))
            ->assertInternalServerError();
        $this->assertDatabaseCount('ground_truth_tree_records', 0);
    }

    // [GT-01] Authentication and a current-tenant record-ground-truth grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $this->postJson($this->uri(Str::uuid()), [])->assertUnauthorized();

        $missing = $this->graph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->postJson($this->uri($missing['session']), $this->payload($missing))
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'validation.record_ground_truth');

        $foreign = $this->graph(permission: false, foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->postJson($this->uri($foreign['session']), $this->payload($foreign))
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'validation.record_ground_truth');
    }

    // [GT-01] Inactive actors cannot record field evidence.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->graph(prefix: 'inactive-actor-');
        DB::table('users')->where('user_id', $graph['actor'])->update(['status' => 'inactive']);

        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), $this->payload($graph))
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [GT-01] Authenticated throttling prevents an additional record and audit event.
    public function test_it_rate_limits_ground_truth_creation(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->graph();
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), $this->payload($graph))->assertCreated();
        $this->withToken($graph['token'])->postJson($this->uri($graph['session']), $this->payload($graph))->assertTooManyRequests();

        $this->assertDatabaseCount('ground_truth_tree_records', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [GT-01] PostgreSQL enforces the approved crown-diameter domain independently of the API.
    public function test_postgresql_rejects_negative_crown_diameter(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL constraint assertion.');
        }

        $graph = $this->graph();
        $this->expectException(QueryException::class);
        DB::insert(<<<'SQL'
            INSERT INTO ground_truth_tree_records (
                ground_truth_id, validation_session_id, ground_location, crown_diameter_m,
                health_status, is_tree, created_at
            ) VALUES (?, ?, ST_SetSRID(ST_MakePoint(123.8, 10.1), 4326), ?, ?, ?, ?)
            SQL, [(string) Str::uuid(), $graph['session'], -0.01, 'healthy', true, now()]);
    }

    // [GT-01] Route, additive schema, and column-limited runtime INSERT are versioned.
    public function test_it_registers_the_route_schema_and_least_privilege_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/validation-sessions/{session}/ground-truth'
            && in_array('POST', $route->methods(), true));
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('permission:validation.record_ground_truth', $route->gatherMiddleware());
        $this->assertContains('throttle:auth.authenticated', $route->gatherMiddleware());

        $migration = file_get_contents(database_path('migrations/2026_08_25_000100_add_ground_truth_capture_fields.php'));
        $dcl = file_get_contents(database_path('sql/dcl/050_ground_truth_creation_grants.sql'));
        $this->assertIsString($migration);
        foreach (['field_code', 'crown_diameter_m', 'is_tree', 'ground_truth_tree_records_crown_diameter_check'] as $fragment) {
            $this->assertStringContainsString($fragment, $migration);
        }
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT INSERT (', $dcl);
        $this->assertStringContainsString('ON TABLE app.ground_truth_tree_records TO mangroscan_api_rw;', $dcl);
        foreach (['GRANT UPDATE', 'GRANT DELETE', 'mangroscan_worker', 'mangroscan_auditor'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    /** @param array<string, string> $graph @return array<string, mixed> */
    private function payload(array $graph): array
    {
        return [
            'field_code' => ' FIELD-001 ',
            'species_id' => strtoupper($graph['species']),
            'location' => ['type' => 'Point', 'coordinates' => [123.81, 10.11]],
            'height_m' => 4.5,
            'age_years' => 6,
            'diameter_cm' => 22.25,
            'crown_diameter_m' => 3.75,
            'health_status' => ' HEALTHY ',
            'is_tree' => true,
            'photo_path' => ' private/validation/FIELD-001.jpg ',
            'notes' => ' Verified in the field. ',
        ];
    }

    private function uri(string $session): string
    {
        return '/api/v1/validation-sessions/'.$session.'/ground-truth';
    }

    /** @return array<string, string> */
    private function graph(bool $permission = true, bool $foreignPermission = false, string $prefix = ''): array
    {
        $ids = [
            'organization' => (string) Str::uuid(), 'foreign_organization' => (string) Str::uuid(),
            'actor' => (string) Str::uuid(), 'foreign_actor' => (string) Str::uuid(),
            'site' => (string) Str::uuid(), 'foreign_site' => (string) Str::uuid(),
            'mission' => (string) Str::uuid(), 'foreign_mission' => (string) Str::uuid(),
            'session' => (string) Str::uuid(), 'foreign_session' => (string) Str::uuid(),
            'inconsistent_session' => (string) Str::uuid(),
            'species' => (string) Str::uuid(), 'inactive_species' => (string) Str::uuid(),
        ];
        $now = now();
        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization'], 'organization_name' => $prefix.'Ground Truth Org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['organization_id' => $ids['foreign_organization'], 'organization_name' => $prefix.'Foreign Ground Truth Org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->user($ids['actor'], $ids['organization'], $prefix.'ground-truth@example.test');
        $this->user($ids['foreign_actor'], $ids['foreign_organization'], $prefix.'foreign-ground-truth@example.test');
        $this->site($ids['site'], $ids['organization'], $ids['actor'], $prefix.'GT-SITE');
        $this->site($ids['foreign_site'], $ids['foreign_organization'], $ids['foreign_actor'], $prefix.'FOREIGN-GT-SITE');
        $this->mission($ids['mission'], $ids['site'], $ids['actor'], $prefix.'GT-MISSION');
        $this->mission($ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor'], $prefix.'FOREIGN-GT-MISSION');
        $this->validationSession($ids['session'], $ids['mission'], $ids['site'], $ids['actor']);
        $this->validationSession($ids['foreign_session'], $ids['foreign_mission'], $ids['foreign_site'], $ids['foreign_actor']);
        $this->validationSession($ids['inconsistent_session'], $ids['mission'], $ids['site'], $ids['foreign_actor']);
        DB::table('mangrove_species')->insert([
            ['species_id' => $ids['species'], 'scientific_name' => $prefix.'Rhizophora mucronata', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['species_id' => $ids['inactive_species'], 'scientific_name' => $prefix.'Inactive species', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $localRole = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRole, 'organization_id' => $ids['organization'], 'role_name' => $prefix.'Ground Truth Recorder', 'role_code' => $prefix.'ground_truth_recorder', 'created_at' => $now, 'updated_at' => $now],
            ['role_id' => $foreignRole, 'organization_id' => $ids['foreign_organization'], 'role_name' => $prefix.'Foreign Ground Truth Recorder', 'role_code' => $prefix.'foreign_ground_truth_recorder', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'validation.record_ground_truth')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'validation.record_ground_truth', 'permission_name' => 'Record ground truth', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('role_permissions')->insert(['role_id' => $foreignRole, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('user_roles')->insert(['user_id' => $ids['foreign_actor'], 'role_id' => $foreignRole, 'created_at' => $now, 'updated_at' => $now]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $localRole, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('user_roles')->insert(['user_id' => $ids['actor'], 'role_id' => $localRole, 'created_at' => $now, 'updated_at' => $now]);
        } elseif ($foreignPermission) {
            DB::table('user_roles')->insert(['user_id' => $ids['actor'], 'role_id' => $foreignRole, 'created_at' => $now, 'updated_at' => $now]);
        }

        /** @var User $actor */
        $actor = User::query()->findOrFail($ids['actor']);

        return $ids + ['token' => $actor->createToken($prefix.'ground-truth-store')->plainTextToken];
    }

    private function user(string $id, string $organization, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $organization, 'first_name' => 'Ground', 'last_name' => 'Recorder', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $organization, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $organization, 'site_name' => $code, 'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mission(string $id, string $site, string $actor, string $code): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $site, 'mission_code' => $code, 'mission_title' => $code, 'mission_objective' => 'Capture field ground truth.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function validationSession(string $id, string $mission, string $site, string $validator): void
    {
        DB::table('validation_sessions')->insert(['validation_session_id' => $id, 'mission_id' => $mission, 'site_id' => $site, 'validated_by' => $validator, 'validation_date' => '2026-08-25', 'method' => 'ground_survey', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }
}
