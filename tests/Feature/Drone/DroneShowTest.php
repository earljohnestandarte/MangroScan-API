<?php

namespace Tests\Feature\Drone;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DroneShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_tenant_drone_with_sorted_safe_sensors(): void
    {
        $g = $this->graph();
        $r = $this->withToken($g['token'])->withHeader('X-Request-ID', 'req_drone_03')->getJson('/api/v1/drones/'.$g['drone']);
        $r->assertOk()->assertJsonPath('data.drone.drone_id', $g['drone'])->assertJsonPath('data.drone.organization_id', $g['org'])
            ->assertJsonPath('data.sensors.0.sensor_name', 'GPS Module')->assertJsonPath('data.sensors.1.sensor_name', 'RGB Camera')
            ->assertJsonPath('data.sensors.1.sensor_type', 'rgb_camera')->assertJsonPath('data.sensors.1.range_meters', '120.50')
            ->assertJsonPath('data.sensors.1.calibration_required', true)->assertJsonPath('meta.request_id', 'req_drone_03');
        $this->assertSame(['sensor_id', 'drone_id', 'sensor_name', 'sensor_type', 'manufacturer', 'model', 'serial_number', 'resolution', 'range_meters', 'calibration_required', 'status', 'created_at', 'updated_at'], array_keys($r->json('data.sensors.0')));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_returns_an_empty_sensor_collection(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->getJson('/api/v1/drones/'.$g['empty_drone'])->assertOk()->assertJsonCount(0, 'data.sensors');
    }

    public function test_it_hides_foreign_deleted_missing_and_malformed_drones(): void
    {
        $g = $this->graph();
        foreach ([$g['foreign_drone'], $g['deleted_drone'], (string) Str::uuid(), 'bad'] as $id) {
            $this->withToken($g['token'])->getJson('/api/v1/drones/'.$id)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    public function test_it_requires_active_authentication_and_drone_read_permission(): void
    {
        $this->getJson('/api/v1/drones/'.Str::uuid())->assertUnauthorized();
        $g = $this->graph();
        DB::table('role_permissions')->delete();
        $this->app['auth']->forgetGuards();
        $this->withToken($g['token'])->getJson('/api/v1/drones/'.$g['drone'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'drones.read');
        DB::table('role_permissions')->insert(['role_id' => $g['role'], 'permission_id' => $g['permission'], 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organizations')->where('organization_id', $g['org'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->withToken($g['token'])->getJson('/api/v1/drones/'.$g['drone'])->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_it_rate_limits_detail_reads(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $u = '/api/v1/drones/'.$g['drone'];
        $this->withToken($g['token'])->getJson($u)->assertOk();
        $this->withToken($g['token'])->getJson($u)->assertTooManyRequests();
    }

    public function test_it_versions_sensor_schema_and_read_only_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_064300_create_drone_sensors_table.php'));
        $dcl = file_get_contents(database_path('sql/dcl/020_drone_sensor_read_grants.sql'));
        $this->assertIsString($migration);
        $this->assertStringContainsString('drone_sensors_type_check', $migration);
        $this->assertStringContainsString('drone_sensors_status_check', $migration);
        $this->assertStringContainsString('drone_sensors_range_check', $migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.drone_sensors TO mangroscan_api_rw, mangroscan_report_ro;', $dcl);
        $this->assertStringNotContainsString('INSERT', $dcl);
        $this->assertStringNotContainsString('mangroscan_worker', $dcl);
    }

    public function test_postgresql_rejects_invalid_sensor_domains(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL constraint verification.');
        }

        $g = $this->graph();
        foreach ([['bad', 'active', 1], ['gps', 'bad', 1], ['gps', 'active', 0]] as [$type, $status, $range]) {
            try {
                $this->sensor((string) Str::uuid(), $g['drone'], 'Bad '.$type, $type, $status, $range);
                $this->fail('Expected constraint failure.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    /** @return array<string, string> */
    private function graph(): array
    {
        $o = (string) Str::uuid();
        $fo = (string) Str::uuid();
        $u = (string) Str::uuid();
        $d = (string) Str::uuid();
        $e = (string) Str::uuid();
        $fd = (string) Str::uuid();
        $dd = (string) Str::uuid();
        DB::table('organizations')->insert([['organization_id' => $o, 'organization_name' => 'Drone Detail', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $fo, 'organization_name' => 'Foreign Drone Detail', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('users')->insert(['user_id' => $u, 'organization_id' => $o, 'first_name' => 'D', 'last_name' => 'V', 'email' => Str::uuid().'@test', 'password' => Hash::make('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $role = (string) Str::uuid();
        $permission = (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $o, 'role_name' => 'Drone Reader', 'role_code' => 'drone_reader', 'is_system_role' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insert(['permission_id' => $permission, 'permission_code' => 'drones.read', 'permission_name' => 'Read drones', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['user_id' => $u, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now()]);
        $this->drone($d, $o, 'Primary');
        $this->drone($e, $o, 'Empty');
        $this->drone($fd, $fo, 'Foreign');
        $this->drone($dd, $o, 'Deleted', true);
        $this->sensor((string) Str::uuid(), $d, 'RGB Camera', 'rgb_camera', 'active', 120.5, true);
        $this->sensor((string) Str::uuid(), $d, 'GPS Module', 'gps', 'active', null, false);

        return ['org' => $o, 'drone' => $d, 'empty_drone' => $e, 'foreign_drone' => $fd, 'deleted_drone' => $dd, 'role' => $role, 'permission' => $permission, 'token' => User::findOrFail($u)->createToken('drone-show')->plainTextToken];
    }

    private function drone(string $id, string $o, string $name, bool $deleted = false): void
    {
        DB::table('drones')->insert(['drone_id' => $id, 'organization_id' => $o, 'drone_name' => $name, 'status' => 'available', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }

    private function sensor(string $id, string $d, string $name, string $type, string $status, ?float $range, bool $calibration = false): void
    {
        DB::table('drone_sensors')->insert(['sensor_id' => $id, 'drone_id' => $d, 'sensor_name' => $name, 'sensor_type' => $type, 'manufacturer' => 'MangroScan', 'model' => 'M1', 'serial_number' => 'S-'.Str::random(8), 'resolution' => '4K', 'range_meters' => $range, 'calibration_required' => $calibration, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }
}
