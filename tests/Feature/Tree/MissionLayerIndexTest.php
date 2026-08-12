<?php

namespace Tests\Feature\Tree;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MissionLayerIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_safe_ordered_layer_metadata_and_filters_type(): void
    {
        $graph = $this->graph();
        $response = $this->withToken($graph['token'])->getJson('/api/v1/missions/'.$graph['mission'].'/layers');
        $response->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.layer_type', 'canopy_height')->assertJsonPath('data.1.layer_name', 'Species Map');
        $this->assertSame(['layer_id', 'mission_id', 'layer_name', 'layer_type', 'style_config', 'is_visible_default', 'created_by', 'created_at', 'updated_at'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString('storage_key', $response->getContent());
        $this->assertDatabaseCount('audit_logs', 0);
        $query = http_build_query(['type' => ' SPECIES_MAP ']);
        $this->withToken($graph['token'])->getJson('/api/v1/missions/'.$graph['mission'].'/layers?'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.layer_name', 'Species Map');
    }

    public function test_it_validates_and_hides_inaccessible_missions(): void
    {
        $graph = $this->graph();
        $this->withToken($graph['token'])->getJson('/api/v1/missions/'.$graph['mission'].'/layers?type=private')->assertUnprocessable();
        foreach ([$graph['foreign_mission'], (string) Str::uuid(), 'bad-id'] as $mission) {
            $this->withToken($graph['token'])->getJson('/api/v1/missions/'.$mission.'/layers')->assertNotFound();
        }DB::table('survey_sites')->where('site_id', $graph['site'])->update(['deleted_at' => now()]);
        $this->withToken($graph['token'])->getJson('/api/v1/missions/'.$graph['mission'].'/layers')->assertNotFound();
    }

    public function test_it_enforces_access_and_throttling(): void
    {
        $auth = $this->graph(prefix: 'auth-');
        $this->getJson('/api/v1/missions/'.$auth['mission'].'/layers')->assertUnauthorized();
        $missing = $this->graph(prefix: 'missing-', permission: false);
        $this->app['auth']->forgetGuards();
        $this->withToken($missing['token'])->getJson('/api/v1/missions/'.$missing['mission'].'/layers')->assertForbidden();
        $inactive = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->withToken($inactive['token'])->getJson('/api/v1/missions/'.$inactive['mission'].'/layers')->assertForbidden();
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $limited = $this->graph(prefix: 'limited-');
        $this->app['auth']->forgetGuards();
        $url = '/api/v1/missions/'.$limited['mission'].'/layers';
        $this->withToken($limited['token'])->getJson($url)->assertOk();
        $this->withToken($limited['token'])->getJson($url)->assertTooManyRequests();
    }

    public function test_it_versions_layer_constraints_and_read_only_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_065700_create_geospatial_layer_tables.php'));
        $dcl = file_get_contents(database_path('sql/dcl/038_geospatial_layer_grants.sql'));
        $this->assertIsString($migration);
        $this->assertStringContainsString('geospatial_layers_type_check', $migration);
        $this->assertStringContainsString("geometry('bounding_geom', 'polygon', 4326)", $migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString('TO mangroscan_api_rw, mangroscan_report_ro;', $dcl);
        $this->assertStringNotContainsString('INSERT', $dcl);
        $this->assertStringNotContainsString('DELETE', $dcl);
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }$graph = $this->graph(prefix: 'constraint-');
        $this->expectException(QueryException::class);
        DB::table('geospatial_layers')->where('mission_id', $graph['mission'])->update(['layer_type' => 'private']);
    }

    private function graph(string $prefix = '', bool $permission = true): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignActor = (string) Str::uuid();
        DB::table('organizations')->insert([['organization_id' => $org, 'organization_name' => $prefix.'Layer Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Layer Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($actor, $org, $prefix.'layer@example.test');
        $this->user($foreignActor, $foreignOrg, $prefix.'foreign-layer@example.test');
        $role = (string) Str::uuid();
        $permissionId = DB::table('permissions')->where('permission_code', 'results.read')->value('permission_id') ?? (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Layer Reader', 'role_code' => $prefix.'layer_reader', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'results.read', 'permission_name' => 'Read results', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }$local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $foreign = $this->lineage($foreignOrg, $foreignActor, $prefix.'FOREIGN');
        foreach ([['Canopy Height', 'canopy_height'], ['Species Map', 'species_map'], ['Tree Points', 'tree_points']] as [$name,$type]) {
            DB::table('geospatial_layers')->insert(['layer_id' => (string) Str::uuid(), 'mission_id' => $local['mission'], 'layer_name' => $name, 'layer_type' => $type, 'storage_key' => 'layers/'.Str::uuid(), 'style_config' => json_encode(['color' => 'green']), 'is_visible_default' => true, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['actor' => $actor, 'site' => $local['site'], 'mission' => $local['mission'], 'foreign_mission' => $foreign['mission'], 'token' => User::query()->findOrFail($actor)->createToken($prefix.'layers')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Layer', 'last_name' => 'Reader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'List layers.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);

        return ['site' => $site, 'mission' => $mission];
    }
}
