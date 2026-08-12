<?php

namespace Tests\Feature\Tree;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MissionTreeCountTest extends TestCase
{
    use RefreshDatabase;

    // [COUNT-01] Current overall and per-species counts use the exact summary resource.
    public function test_it_returns_current_mission_and_species_counts(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->getJson('/api/v1/missions/'.$graph['mission_id'].'/tree-counts');

        $response->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.species_id', null)
            ->assertJsonPath('data.0.total_detected_trees', 4)
            ->assertJsonPath('data.0.validated_tree_count', 3)
            ->assertJsonPath('data.1.species_id', min($graph['species_id'], $graph['other_species_id']));
        $this->assertSame([
            'tree_count_summary_id', 'mission_id', 'site_id', 'species_id', 'model_run_id',
            'total_detected_trees', 'validated_tree_count', 'estimated_density_per_hectare',
            'count_confidence_score', 'created_at', 'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertDatabaseCount('tree_count_summaries', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [COUNT-01] A species filter returns only that live summary and deleted trees never count.
    public function test_it_filters_by_species_and_excludes_deleted_observations(): void
    {
        $graph = $this->createGraph();
        $this->withToken($graph['token'])
            ->getJson('/api/v1/missions/'.$graph['mission_id'].'/tree-counts?species_id='.$graph['species_id'])
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.species_id', $graph['species_id'])
            ->assertJsonPath('data.0.total_detected_trees', 2)
            ->assertJsonPath('data.0.validated_tree_count', 1);
    }

    // [COUNT-01] Empty and unmatched species scopes return their mathematically valid shapes.
    public function test_it_returns_empty_species_rows_and_a_zero_overall_summary(): void
    {
        $graph = $this->createGraph();
        DB::table('tree_observations')->where('mission_id', $graph['mission_id'])->update(['deleted_at' => now()]);
        $base = '/api/v1/missions/'.$graph['mission_id'].'/tree-counts';
        $this->withToken($graph['token'])->getJson($base)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.total_detected_trees', 0);
        $this->withToken($graph['token'])->getJson($base.'?species_id='.$graph['species_id'])
            ->assertOk()->assertJsonPath('data', []);
    }

    // [COUNT-01] Invalid and inaccessible mission/species identifiers are rejected safely.
    public function test_it_validates_and_hides_inaccessible_resources(): void
    {
        $graph = $this->createGraph();
        $base = '/api/v1/missions/'.$graph['mission_id'].'/tree-counts';
        $this->withToken($graph['token'])->getJson($base.'?species_id=nope')
            ->assertUnprocessable()->assertJsonValidationErrors(['species_id'], 'error.details');
        foreach ([$graph['foreign_mission_id'], (string) Str::uuid()] as $mission) {
            $this->withToken($graph['token'])->getJson('/api/v1/missions/'.$mission.'/tree-counts')
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $this->withToken($graph['token'])->getJson($base.'?species_id='.(string) Str::uuid())
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // [COUNT-01] Authentication, tenant-valid permission, identity state and throttling are enforced.
    public function test_it_enforces_access_and_rate_limits(): void
    {
        $auth = $this->createGraph(prefix: 'auth-');
        $this->getJson('/api/v1/missions/'.$auth['mission_id'].'/tree-counts')->assertUnauthorized();

        $missing = $this->createGraph(prefix: 'missing-', permission: false);
        $this->app['auth']->forgetGuards();
        $this->withToken($missing['token'])->getJson('/api/v1/missions/'.$missing['mission_id'].'/tree-counts')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'results.read');

        $inactive = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor_id'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->withToken($inactive['token'])->getJson('/api/v1/missions/'.$inactive['mission_id'].'/tree-counts')
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');

        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $limited = $this->createGraph(prefix: 'limited-');
        $this->app['auth']->forgetGuards();
        $url = '/api/v1/missions/'.$limited['mission_id'].'/tree-counts';
        $this->withToken($limited['token'])->getJson($url)->assertOk();
        $this->withToken($limited['token'])->getJson($url)->assertTooManyRequests();
    }

    // [COUNT-01] Summary constraints, count routine and split DCL are version controlled.
    public function test_it_versions_count_schema_routine_and_privileges(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_065500_create_tree_count_summaries.php'));
        $dcl = file_get_contents(database_path('sql/dcl/036_tree_count_summary_grants.sql'));
        $this->assertIsString($migration);
        foreach (['mission_tree_counts', 'tree_count_summaries_validated_check', 'tree_count_summaries_confidence_check'] as $guard) {
            $this->assertStringContainsString($guard, $migration);
        }
        $this->assertIsString($dcl);
        $this->assertStringContainsString('TO mangroscan_api_rw, mangroscan_report_ro;', $dcl);
        $this->assertStringContainsString('TO mangroscan_worker;', $dcl);
        $this->assertStringNotContainsString('DELETE', $dcl);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $graph = $this->createGraph(prefix: 'constraint-');
        $this->expectException(QueryException::class);
        DB::table('tree_count_summaries')->insert(['tree_count_summary_id' => (string) Str::uuid(), 'mission_id' => $graph['mission_id'], 'site_id' => $graph['site_id'], 'total_detected_trees' => 1, 'validated_tree_count' => 2, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, string> */
    private function createGraph(string $prefix = '', bool $permission = true): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignActor = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => $prefix.'Count Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Count Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, $prefix.'count@example.test');
        $this->user($foreignActor, $foreignOrg, $prefix.'foreign-count@example.test');
        $role = (string) Str::uuid();
        $permissionId = DB::table('permissions')->where('permission_code', 'results.read')->value('permission_id') ?? (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Count Reader', 'role_code' => $prefix.'count_reader', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'results.read', 'permission_name' => 'Read results', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $foreign = $this->lineage($foreignOrg, $foreignActor, $prefix.'FOREIGN');
        $species = $this->species($prefix.'Rhizophora mucronata');
        $otherSpecies = $this->species($prefix.'Avicennia marina');
        $this->tree($local['mission'], $local['flight'], 'TREE-001', $species, 'validated');
        $this->tree($local['mission'], $local['flight'], 'TREE-002', $species, 'unvalidated');
        $this->tree($local['mission'], $local['flight'], 'TREE-003', $otherSpecies, 'corrected');
        $this->tree($local['mission'], $local['flight'], 'TREE-004', null, 'validated');
        $this->tree($local['mission'], $local['flight'], 'TREE-DELETED', $species, 'validated', true);
        $this->tree($foreign['mission'], $foreign['flight'], 'TREE-FOREIGN', $species, 'validated');

        return ['actor_id' => $actor, 'site_id' => $local['site'], 'mission_id' => $local['mission'], 'foreign_mission_id' => $foreign['mission'], 'species_id' => $species, 'other_species_id' => $otherSpecies, 'token' => User::query()->findOrFail($actor)->createToken($prefix.'counts')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Count', 'last_name' => 'Reader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{site:string,mission:string,flight:string} */
    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Count trees.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => $code, 'model' => 'Test', 'serial_number' => $code.'-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);

        return ['site' => $site, 'mission' => $mission, 'flight' => $flight];
    }

    private function species(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('mangrove_species')->insert(['species_id' => $id, 'scientific_name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function tree(string $mission, string $flight, string $code, ?string $species, string $status, bool $deleted = false): void
    {
        $point = json_encode(['type' => 'Point', 'coordinates' => [123.3, 9.3]], JSON_THROW_ON_ERROR);
        DB::table('tree_observations')->insert(['tree_observation_id' => (string) Str::uuid(), 'mission_id' => $mission, 'flight_session_id' => $flight, 'tree_code' => $code, 'tree_location' => DB::getDriverName() === 'pgsql' ? DB::raw("ST_SetSRID(ST_GeomFromGeoJSON('$point'),4326)") : $point, 'final_species_id' => $species, 'validation_status' => $status, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }
}
