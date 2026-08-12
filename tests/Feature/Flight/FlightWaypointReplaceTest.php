<?php

namespace Tests\Feature\Flight;

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

class FlightWaypointReplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_atomically_replaces_ordered_waypoints(): void
    {
        $g = $this->graph();
        $response = $this->withToken($g['token'])->withHeader('X-Request-ID', 'req_wpt_01')
            ->putJson('/api/v1/flights/'.$g['flight'].'/waypoints', $this->payload());
        $response->assertOk()->assertJsonPath('data.count', 2)->assertJsonPath('meta.request_id', 'req_wpt_01');
        $this->assertDatabaseMissing('flight_waypoints', ['waypoint_id' => $g['old_waypoint']]);
        $this->assertSame([2, 5], DB::table('flight_waypoints')->where('flight_session_id', $g['flight'])->orderBy('sequence_no')->pluck('sequence_no')->map(fn ($v) => (int) $v)->all());
        $audit = AuditLog::query()->sole();
        $this->assertSame('flight.waypoints.replace', $audit->action);
        $this->assertSame(1, count($audit->old_values['waypoints']));
        $this->assertSame(2, $audit->new_values['count']);
        $this->assertSame(['type' => 'Point', 'coordinates' => [123.9, 10.3]], $audit->new_values['waypoints'][0]['location']);
    }

    public function test_it_accepts_an_empty_array_to_clear_the_route(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->putJson('/api/v1/flights/'.$g['flight'].'/waypoints', ['waypoints' => []])
            ->assertOk()->assertJsonPath('data.count', 0);
        $this->assertDatabaseCount('flight_waypoints', 0);
    }

    public function test_it_validates_sequences_points_motion_and_actions(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->putJson('/api/v1/flights/'.$g['flight'].'/waypoints', ['waypoints' => [
            ['sequence_no' => -1, 'location' => ['type' => 'LineString', 'coordinates' => [181, 91, 1]], 'altitude_meters' => -1, 'speed_mps' => '1000000', 'action' => 'land'],
            ['sequence_no' => -1, 'location' => ['type' => 'Point', 'coordinates' => [0, 0]]],
        ]])->assertUnprocessable()->assertJsonValidationErrors([
            'waypoints', 'waypoints.0.sequence_no', 'waypoints.0.location.type', 'waypoints.0.location.coordinates',
            'waypoints.0.location.coordinates.0', 'waypoints.0.location.coordinates.1',
            'waypoints.0.altitude_meters', 'waypoints.0.speed_mps', 'waypoints.0.action',
        ], 'error.details');
        $this->assertDatabaseHas('flight_waypoints', ['waypoint_id' => $g['old_waypoint']]);
    }

    public function test_it_normalizes_actions_and_numeric_coordinates(): void
    {
        $g = $this->graph();
        $payload = ['waypoints' => [['sequence_no' => 0, 'location' => ['type' => 'Point', 'coordinates' => ['123.5', '10.5']], 'action' => ' RETURN_HOME ']]];
        $this->withToken($g['token'])->putJson('/api/v1/flights/'.$g['flight'].'/waypoints', $payload)->assertOk();
        $audit = AuditLog::query()->sole();
        $this->assertSame('return_home', $audit->new_values['waypoints'][0]['action']);
        $this->assertSame([123.5, 10.5], $audit->new_values['waypoints'][0]['location']['coordinates']);
    }

    public function test_it_requires_a_planned_tenant_flight(): void
    {
        foreach (['flying', 'completed', 'aborted', 'failed'] as $status) {
            $g = $this->graph(status: $status);
            $this->withToken($g['token'])->putJson('/api/v1/flights/'.$g['flight'].'/waypoints', ['waypoints' => []])
                ->assertConflict()->assertJsonPath('error.details.current_status', $status);
            $this->app['auth']->forgetGuards();
        }
        $g = $this->graph();
        foreach ([$g['foreign_flight'], $g['deleted_flight'], (string) Str::uuid(), 'bad'] as $id) {
            $this->withToken($g['token'])->putJson('/api/v1/flights/'.$id.'/waypoints', ['waypoints' => []])->assertNotFound();
        }
    }

    public function test_it_rolls_back_replacement_when_audit_fails(): void
    {
        $g = $this->graph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($g['token'])->putJson('/api/v1/flights/'.$g['flight'].'/waypoints', $this->payload())->assertInternalServerError();
        $this->assertDatabaseHas('flight_waypoints', ['waypoint_id' => $g['old_waypoint'], 'sequence_no' => 1]);
        $this->assertDatabaseCount('flight_waypoints', 1);
    }

    public function test_it_enforces_permission_throttling_and_write_dcl(): void
    {
        $g = $this->graph(permission: false);
        $url = '/api/v1/flights/'.$g['flight'].'/waypoints';
        $this->putJson($url, ['waypoints' => []])->assertUnauthorized();
        $this->withToken($g['token'])->putJson($url, ['waypoints' => []])->assertForbidden()->assertJsonPath('error.details.required_permission', 'flights.update');
        $this->app['auth']->forgetGuards();
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $active = $this->graph();
        $activeUrl = '/api/v1/flights/'.$active['flight'].'/waypoints';
        $this->withToken($active['token'])->putJson($activeUrl, ['waypoints' => []])->assertOk();
        $this->withToken($active['token'])->putJson($activeUrl, ['waypoints' => []])->assertTooManyRequests();
        $dcl = file_get_contents(database_path('sql/dcl/022_flight_waypoint_write_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT INSERT, DELETE ON TABLE app.flight_waypoints TO mangroscan_api_rw;', $dcl);
        $this->assertStringNotContainsString('UPDATE', $dcl);
        $this->assertStringNotContainsString('mangroscan_report_ro', $dcl);
    }

    /** @return array{waypoints:list<array<string,mixed>>} */
    private function payload(): array
    {
        return ['waypoints' => [
            ['sequence_no' => 5, 'location' => ['type' => 'Point', 'coordinates' => [124.0, 10.4]], 'altitude_meters' => null, 'speed_mps' => '6.25', 'action' => null],
            ['sequence_no' => 2, 'location' => ['type' => 'Point', 'coordinates' => [123.9, 10.3]], 'altitude_meters' => '50.50', 'speed_mps' => null, 'action' => ' CAPTURE '],
        ]];
    }

    /** @return array<string,string> */
    private function graph(string $status = 'planned', bool $permission = true): array
    {
        $o = (string) Str::uuid();
        $fo = (string) Str::uuid();
        $u = (string) Str::uuid();
        $fu = (string) Str::uuid();
        $suffix = Str::upper(Str::random(8));
        DB::table('organizations')->insert([['organization_id' => $o, 'organization_name' => 'Waypoint '.$suffix, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $fo, 'organization_name' => 'Foreign Waypoint '.$suffix, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($u, $o);
        $this->user($fu, $fo);
        $p = DB::table('permissions')->where('permission_code', 'flights.update')->value('permission_id') ?? (string) Str::uuid();
        $role = (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $o, 'role_name' => 'Route Planner', 'role_code' => 'route-'.Str::lower(Str::random(8)), 'created_at' => now(), 'updated_at' => now()]);
        if (! DB::table('permissions')->where('permission_id', $p)->exists()) {
            DB::table('permissions')->insert(['permission_id' => $p, 'permission_code' => 'flights.update', 'permission_name' => 'Update flights', 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $p, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $u, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $s = (string) Str::uuid();
        $fs = (string) Str::uuid();
        $ds = (string) Str::uuid();
        $this->site($s, $o, $u, 'WPT-S-'.$suffix);
        $this->site($fs, $fo, $fu, 'FWPT-S-'.$suffix);
        $this->site($ds, $o, $u, 'DWPT-S-'.$suffix, true);
        $m = (string) Str::uuid();
        $fm = (string) Str::uuid();
        $dm = (string) Str::uuid();
        $this->mission($m, $s, $u, 'WPT-M-'.$suffix);
        $this->mission($fm, $fs, $fu, 'FWPT-M-'.$suffix);
        $this->mission($dm, $ds, $u, 'DWPT-M-'.$suffix);
        $d = (string) Str::uuid();
        $fd = (string) Str::uuid();
        $this->drone($d, $o, 'WPT-D-'.$suffix);
        $this->drone($fd, $fo, 'FWPT-D-'.$suffix);
        $f = (string) Str::uuid();
        $ff = (string) Str::uuid();
        $df = (string) Str::uuid();
        $this->flight($f, $m, $d, $u, 'WPT-F-'.$suffix, $status);
        $this->flight($ff, $fm, $fd, $fu, 'FWPT-F-'.$suffix, 'planned');
        $this->flight($df, $dm, $d, $u, 'DWPT-F-'.$suffix, 'planned');
        $old = (string) Str::uuid();
        $this->waypoint($old, $f);

        return ['flight' => $f, 'foreign_flight' => $ff, 'deleted_flight' => $df, 'old_waypoint' => $old, 'token' => User::findOrFail($u)->createToken('wpt')->plainTextToken];
    }

    private function user(string $id, string $o): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $o, 'first_name' => 'R', 'last_name' => 'P', 'email' => Str::uuid().'@test', 'password' => Hash::make('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $o, string $u, string $c, bool $deleted = false): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $o, 'site_name' => $c, 'site_code' => $c, 'province' => 'P', 'city_municipality' => 'C', 'environment_type' => 'coastal', 'status' => 'active', 'created_by' => $u, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }

    private function mission(string $id, string $s, string $u, string $c): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $s, 'mission_code' => $c, 'mission_title' => $c, 'mission_objective' => 'O', 'mission_status' => 'planned', 'created_by' => $u, 'approved_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function drone(string $id, string $o, string $serial): void
    {
        DB::table('drones')->insert(['drone_id' => $id, 'organization_id' => $o, 'drone_name' => $serial, 'serial_number' => $serial, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function flight(string $id, string $m, string $d, string $u, string $c, string $status): void
    {
        DB::table('flight_sessions')->insert(['flight_session_id' => $id, 'mission_id' => $m, 'drone_id' => $d, 'pilot_user_id' => $u, 'flight_code' => $c, 'flight_status' => $status, 'quality_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function waypoint(string $id, string $flight): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::insert('INSERT INTO flight_waypoints (waypoint_id, flight_session_id, sequence_no, waypoint_location, action, created_at) VALUES (?, ?, 1, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?, ?)', [$id, $flight, json_encode(['type' => 'Point', 'coordinates' => [123.8, 10.2]]), 'hover', now()]);
        } else {
            DB::table('flight_waypoints')->insert(['waypoint_id' => $id, 'flight_session_id' => $flight, 'sequence_no' => 1, 'waypoint_location' => json_encode(['type' => 'Point', 'coordinates' => [123.8, 10.2]]), 'action' => 'hover', 'created_at' => now()]);
        }
    }
}
