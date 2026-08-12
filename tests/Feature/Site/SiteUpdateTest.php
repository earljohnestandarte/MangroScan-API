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

class SiteUpdateTest extends TestCase
{
    use RefreshDatabase;

    // [SITE-04] Managers update normalized metadata and Point(4326) with audit evidence.
    public function test_it_updates_site_metadata_and_point(): void
    {
        $g = $this->graph();
        $response = $this->withToken($g['token'])->withHeader('X-Request-ID', 'req_site_04')
            ->patchJson('/api/v1/sites/'.$g['site'], [
                'site_name' => ' Updated Site ', 'site_code' => ' updated-01 ', 'description' => null,
                'province' => ' Cebu ', 'city_municipality' => ' Cebu City ', 'barangay' => ' Coastal ',
                'center_point' => ['type' => 'Point', 'coordinates' => ['123.90', '10.20']],
                'area_hectares' => '12.3456', 'environment_type' => ' RIVERINE ', 'access_notes' => ' Boat ',
            ]);
        $response->assertOk()->assertJsonPath('data.site_code', 'UPDATED-01')
            ->assertJsonPath('data.center_point.coordinates.0', 123.9)->assertJsonPath('data.center_point.coordinates.1', 10.2)
            ->assertJsonPath('data.area_hectares', '12.3456')->assertJsonPath('data.environment_type', 'riverine')
            ->assertJsonPath('data.organization_id', $g['org'])->assertJsonPath('data.created_by', $g['actor'])
            ->assertJsonPath('data.status', 'active')->assertJsonPath('meta.request_id', 'req_site_04');
        $audit = AuditLog::query()->sole();
        $this->assertSame('site.update', $audit->action);
        $this->assertSame('ORIGINAL-01', $audit->old_values['site_code']);
        $this->assertSame('UPDATED-01', $audit->new_values['site_code']);
        $this->assertSame([123.9, 10.2], $audit->new_values['center_point']['coordinates']);
    }

    // [SITE-04] Nullable metadata/point may be cleared while ownership and lifecycle stay fixed.
    public function test_it_clears_nullable_fields_and_preserves_omitted_fields(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->patchJson('/api/v1/sites/'.$g['site'], [
            'description' => null, 'barangay' => null, 'center_point' => null, 'area_hectares' => null, 'access_notes' => null,
        ])->assertOk()->assertJsonPath('data.site_name', 'Original Site')->assertJsonPath('data.center_point', null)
            ->assertJsonPath('data.area_hectares', null)->assertJsonPath('data.status', 'active');
    }

    // [SITE-04] Empty, lifecycle/ownership-only and malformed changes fail validation.
    public function test_it_validates_partial_updates(): void
    {
        $g = $this->graph();
        $uri = '/api/v1/sites/'.$g['site'];
        $this->withToken($g['token'])->patchJson($uri, [])->assertUnprocessable()->assertJsonValidationErrors(['request'], 'error.details');
        $this->withToken($g['token'])->patchJson($uri, ['status' => 'archived'])->assertUnprocessable()->assertJsonValidationErrors(['request'], 'error.details');
        $this->withToken($g['token'])->patchJson($uri, ['site_name' => ' ', 'site_code' => ' ', 'environment_type' => 'forest', 'center_point' => ['type' => 'Point', 'coordinates' => [181, 91]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['site_name', 'site_code', 'environment_type', 'center_point.coordinates.0', 'center_point.coordinates.1'], 'error.details');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [SITE-04] Active and deleted site codes remain reserved globally.
    public function test_it_rejects_reserved_site_codes(): void
    {
        $g = $this->graph();
        foreach ([' DUPLICATE-01 ', ' DELETED-01 '] as $code) {
            $this->withToken($g['token'])->patchJson('/api/v1/sites/'.$g['site'], ['site_code' => $code])
                ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        }
        $this->assertDatabaseHas('survey_sites', ['site_id' => $g['site'], 'site_code' => 'ORIGINAL-01']);
    }

    // [SITE-04] Foreign, deleted, missing and malformed sites remain hidden.
    public function test_it_hides_unavailable_sites(): void
    {
        $g = $this->graph();
        foreach ([$g['foreign_site'], $g['deleted_site'], (string) Str::uuid(), 'bad'] as $id) {
            $this->withToken($g['token'])->patchJson('/api/v1/sites/'.$id, ['site_name' => 'No'])->assertNotFound();
        }
    }

    // [SITE-04] Audit failure rolls back both metadata and geometry.
    public function test_it_rolls_back_when_audit_fails(): void
    {
        $g = $this->graph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($g['token'])->patchJson('/api/v1/sites/'.$g['site'], ['site_name' => 'Changed', 'center_point' => ['type' => 'Point', 'coordinates' => [125, 11]]])->assertInternalServerError();
        $this->assertDatabaseHas('survey_sites', ['site_id' => $g['site'], 'site_name' => 'Original Site']);
    }

    // [SITE-04] Authentication and tenant-valid sites.manage are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->patchJson('/api/v1/sites/'.Str::uuid(), ['site_name' => 'No'])->assertUnauthorized();
        $g = $this->graph(local: false);
        $this->withToken($g['token'])->patchJson('/api/v1/sites/'.$g['site'], ['site_name' => 'No'])->assertForbidden();
    }

    // [SITE-04] Foreign-role permission assignments cannot authorize updates.
    public function test_it_rejects_foreign_tenant_permission(): void
    {
        $g = $this->graph(local: false, foreign: true);
        $this->withToken($g['token'])->patchJson('/api/v1/sites/'.$g['site'], ['site_name' => 'No'])->assertForbidden();
    }

    public function test_it_rate_limits_updates(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $uri = '/api/v1/sites/'.$g['site'];
        $this->withToken($g['token'])->patchJson($uri, ['site_name' => 'First'])->assertOk();
        $this->withToken($g['token'])->patchJson($uri, ['site_name' => 'Second'])->assertTooManyRequests();
        $this->assertDatabaseHas('survey_sites', ['site_id' => $g['site'], 'site_name' => 'First']);
    }

    // [SITE-04] Additive DCL grants API UPDATE only; reporting remains read-only.
    public function test_it_versions_update_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/017_survey_site_update_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT UPDATE ON TABLE app.survey_sites TO mangroscan_api_rw;', $dcl);
        $this->assertStringNotContainsString('mangroscan_report_ro', $dcl);
        $this->assertStringNotContainsString('mangroscan_worker', $dcl);
    }

    /** @return array<string, string> */
    private function graph(bool $local = true, bool $foreign = false): array
    {
        $org = (string) Str::uuid();
        $fo = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $fu = (string) Str::uuid();
        $role = (string) Str::uuid();
        $fr = (string) Str::uuid();
        $perm = (string) Str::uuid();
        $site = (string) Str::uuid();
        $fs = (string) Str::uuid();
        $duplicate = (string) Str::uuid();
        $deleted = (string) Str::uuid();
        DB::table('organizations')->insert([['organization_id' => $org, 'organization_name' => 'Site Update Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $fo, 'organization_name' => 'Foreign Site Update Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($actor, $org, 'site-update-'.Str::uuid().'@test');
        $this->user($fu, $fo, 'foreign-site-update-'.Str::uuid().'@test');
        DB::table('roles')->insert([['role_id' => $role, 'organization_id' => $org, 'role_name' => 'Site Manager', 'role_code' => 'site_update_'.Str::lower(Str::random(6)), 'created_at' => now(), 'updated_at' => now()], ['role_id' => $fr, 'organization_id' => $fo, 'role_name' => 'Foreign Site Manager', 'role_code' => 'foreign_site_update_'.Str::lower(Str::random(6)), 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('permissions')->insert(['permission_id' => $perm, 'permission_code' => 'sites.manage', 'permission_name' => 'Manage sites', 'created_at' => now(), 'updated_at' => now()]);
        if ($local || $foreign) {
            DB::table('role_permissions')->insert(['role_id' => $foreign ? $fr : $role, 'permission_id' => $perm, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $foreign ? $fr : $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->site($site, $org, $actor, 'Original Site', 'ORIGINAL-01');
        $this->site($fs, $fo, $fu, 'Foreign Site', 'FOREIGN-01');
        $this->site($duplicate, $org, $actor, 'Duplicate Site', 'DUPLICATE-01');
        $this->site($deleted, $org, $actor, 'Deleted Site', 'DELETED-01', true);
        $this->point($site, [123.8, 10.1]);

        return ['org' => $org, 'actor' => $actor, 'site' => $site, 'foreign_site' => $fs, 'deleted_site' => $deleted, 'token' => User::findOrFail($actor)->createToken('site-update')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Site', 'last_name' => 'Manager', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $org, string $actor, string $name, string $code, bool $deleted = false): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $org, 'site_name' => $name, 'site_code' => $code, 'description' => 'Old', 'province' => 'Negros', 'city_municipality' => 'Dumaguete', 'barangay' => 'Old', 'area_hectares' => 1, 'environment_type' => 'coastal', 'access_notes' => 'Road', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }

    private function point(string $site, array $coordinates): void
    {
        $json = json_encode(['type' => 'Point', 'coordinates' => $coordinates], JSON_THROW_ON_ERROR);
        if (DB::getDriverName() === 'pgsql') {
            DB::update('UPDATE survey_sites SET center_point=ST_SetSRID(ST_GeomFromGeoJSON(?),4326) WHERE site_id=?', [$json, $site]);
        } else {
            DB::table('survey_sites')->where('site_id', $site)->update(['center_point' => $json]);
        }
    }
}
