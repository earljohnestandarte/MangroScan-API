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

class SiteBoundaryUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_metadata_and_polygon_with_audit(): void
    {
        $g = $this->graph();
        $geom = ['type' => 'Polygon', 'coordinates' => [[['123.9', '10.1'], [124, 10.1], [124, 10.2], ['123.9', '10.1']]]];
        $r = $this->withToken($g['token'])->withHeader('X-Request-ID', 'req_bound_03')->patchJson('/api/v1/boundaries/'.$g['boundary'], ['boundary_name' => ' Updated ', 'boundary_type' => ' NO_FLY_ZONE ', 'source' => ' DRONE_MAP ', 'boundary_geom' => $geom]);
        $r->assertOk()->assertJsonPath('data.boundary_name', 'Updated')->assertJsonPath('data.boundary_type', 'no_fly_zone')->assertJsonPath('data.source', 'drone_map')->assertJsonPath('data.boundary_geom.coordinates.0.0.0', 123.9)->assertJsonPath('data.site_id', $g['site'])->assertJsonPath('meta.request_id', 'req_bound_03');
        $a = AuditLog::query()->sole();
        $this->assertSame('boundary.update', $a->action);
        $this->assertSame('Original', $a->old_values['boundary_name']);
        $this->assertSame('Updated', $a->new_values['boundary_name']);
    }

    public function test_it_supports_partial_updates_and_nullable_source(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->patchJson('/api/v1/boundaries/'.$g['boundary'], ['source' => null])->assertOk()->assertJsonPath('data.boundary_name', 'Original')->assertJsonPath('data.source', null);
    }

    public function test_it_validates_partial_updates(): void
    {
        $g = $this->graph();
        $u = '/api/v1/boundaries/'.$g['boundary'];
        $this->withToken($g['token'])->patchJson($u, [])->assertUnprocessable()->assertJsonValidationErrors(['request'], 'error.details');
        $this->withToken($g['token'])->patchJson($u, ['boundary_name' => ' ', 'boundary_type' => 'bad', 'source' => 'bad', 'boundary_geom' => ['type' => 'Polygon', 'coordinates' => [[[181, 10], [124, 10], [124, 11], [181, 10]]]]])->assertUnprocessable()->assertJsonValidationErrors(['boundary_name', 'boundary_type', 'source', 'boundary_geom'], 'error.details');
    }

    public function test_it_hides_foreign_missing_and_malformed_boundaries(): void
    {
        $g = $this->graph();
        foreach ([$g['foreign'], (string) Str::uuid(), 'bad'] as $id) {
            $this->withToken($g['token'])->patchJson('/api/v1/boundaries/'.$id, ['boundary_name' => 'No'])->assertNotFound();
        }
    }

    public function test_it_rolls_back_geometry_when_audit_fails(): void
    {
        $g = $this->graph();
        $a = Mockery::mock(AuditLogger::class);
        $a->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $a);
        $this->withToken($g['token'])->patchJson('/api/v1/boundaries/'.$g['boundary'], ['boundary_name' => 'Changed'])->assertInternalServerError();
        $this->assertDatabaseHas('site_boundaries', ['boundary_id' => $g['boundary'], 'boundary_name' => 'Original']);
    }

    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->patchJson('/api/v1/boundaries/'.Str::uuid(), ['boundary_name' => 'No'])->assertUnauthorized();
        $g = $this->graph(permission: false);
        $this->withToken($g['token'])->patchJson('/api/v1/boundaries/'.$g['boundary'], ['boundary_name' => 'No'])->assertForbidden();
    }

    public function test_it_rate_limits_updates(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $u = '/api/v1/boundaries/'.$g['boundary'];
        $this->withToken($g['token'])->patchJson($u, ['boundary_name' => 'First'])->assertOk();
        $this->withToken($g['token'])->patchJson($u, ['boundary_name' => 'Second'])->assertTooManyRequests();
    }

    public function test_it_versions_update_dcl(): void
    {
        $d = file_get_contents(database_path('sql/dcl/018_site_boundary_update_grants.sql'));
        $this->assertIsString($d);
        $this->assertStringContainsString('GRANT UPDATE ON TABLE app.site_boundaries TO mangroscan_api_rw;', $d);
        $this->assertStringNotContainsString('mangroscan_report_ro', $d);
    }

    /** @return array<string,string> */
    private function graph(bool $permission = true): array
    {
        $o = (string) Str::uuid();
        $fo = (string) Str::uuid();
        $u = (string) Str::uuid();
        $fu = (string) Str::uuid();
        $r = (string) Str::uuid();
        $p = (string) Str::uuid();
        $s = (string) Str::uuid();
        $fs = (string) Str::uuid();
        $b = (string) Str::uuid();
        $fb = (string) Str::uuid();
        DB::table('organizations')->insert([['organization_id' => $o, 'organization_name' => 'Boundary Update', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $fo, 'organization_name' => 'Foreign Boundary Update', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($u, $o);
        $this->user($fu, $fo);
        DB::table('roles')->insert(['role_id' => $r, 'organization_id' => $o, 'role_name' => 'Boundary Manager', 'role_code' => 'boundary_update_'.Str::lower(Str::random(6)), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insert(['permission_id' => $p, 'permission_code' => 'boundaries.manage', 'permission_name' => 'Manage boundaries', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $r, 'permission_id' => $p, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $u, 'role_id' => $r, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->site($s, $o, $u, 'S');
        $this->site($fs, $fo, $fu, 'F');
        $this->boundary($b, $s, $u);
        $this->boundary($fb, $fs, $fu);

        return ['site' => $s, 'boundary' => $b, 'foreign' => $fb, 'token' => User::findOrFail($u)->createToken('bound-update')->plainTextToken];
    }

    private function user(string $id, string $o): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $o, 'first_name' => 'B', 'last_name' => 'M', 'email' => Str::uuid().'@test', 'password' => Hash::make('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $o, string $u, string $c): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $o, 'site_name' => $c, 'site_code' => 'B-'.$c.'-'.Str::random(4), 'province' => 'P', 'city_municipality' => 'C', 'environment_type' => 'coastal', 'status' => 'active', 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function boundary(string $id, string $s, string $u): void
    {
        $j = json_encode(['type' => 'Polygon', 'coordinates' => [[[123.8, 10.1], [123.9, 10.1], [123.9, 10.2], [123.8, 10.1]]]], JSON_THROW_ON_ERROR);
        if (DB::getDriverName() === 'pgsql') {
            DB::insert('INSERT INTO site_boundaries(boundary_id,site_id,boundary_name,boundary_type,boundary_geom,source,created_by,created_at,updated_at) VALUES(?,?,?,?,ST_SetSRID(ST_GeomFromGeoJSON(?),4326),?,?,?,?)', [$id, $s, 'Original', 'survey_area', $j, 'manual', $u, now(), now()]);
        } else {
            DB::table('site_boundaries')->insert(['boundary_id' => $id, 'site_id' => $s, 'boundary_name' => 'Original', 'boundary_type' => 'survey_area', 'boundary_geom' => $j, 'source' => 'manual', 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
