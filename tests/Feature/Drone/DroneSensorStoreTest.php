<?php

namespace Tests\Feature\Drone;

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

class DroneSensorStoreTest extends TestCase
{
    use RefreshDatabase;

    // [SENSOR-01] Active tenant users attach normalized sensors with immutable evidence.
    public function test_it_registers_a_sensor_with_audit_evidence(): void
    {
        $g = $this->graph();
        $response = $this->withToken($g['token'])->withHeader('X-Request-ID', 'req_sensor_01')
            ->postJson('/api/v1/drones/'.$g['drone'].'/sensors', $this->payload());

        $response->assertCreated()->assertJsonPath('data.drone_id', $g['drone'])
            ->assertJsonPath('data.sensor_name', 'MangroScan RGB Camera')->assertJsonPath('data.sensor_type', 'rgb_camera')
            ->assertJsonPath('data.manufacturer', 'MangroScan Labs')->assertJsonPath('data.model', 'Vision One')
            ->assertJsonPath('data.serial_number', 'SENSOR-001')->assertJsonPath('data.resolution', '4K')
            ->assertJsonPath('data.range_meters', '120.50')->assertJsonPath('data.calibration_required', true)
            ->assertJsonPath('data.status', 'active')->assertJsonPath('meta.request_id', 'req_sensor_01');
        $this->assertSame(
            ['sensor_id', 'drone_id', 'sensor_name', 'sensor_type', 'manufacturer', 'model', 'serial_number', 'resolution', 'range_meters', 'calibration_required', 'status', 'created_at', 'updated_at'],
            array_keys($response->json('data')),
        );
        $audit = AuditLog::query()->sole();
        $this->assertSame('sensor.create', $audit->action);
        $this->assertSame($response->json('data.sensor_id'), $audit->record_id);
        $this->assertSame($g['drone'], $audit->new_values['drone_id']);
        $this->assertSame('SENSOR-001', $audit->new_values['serial_number']);
    }

    public function test_it_accepts_omitted_optional_metadata(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/drones/'.$g['drone'].'/sensors', [
            'sensor_name' => 'GPS', 'sensor_type' => 'gps', 'calibration_required' => false, 'status' => 'inactive',
        ])->assertCreated()->assertJsonPath('data.manufacturer', null)->assertJsonPath('data.serial_number', null)
            ->assertJsonPath('data.range_meters', null)->assertJsonPath('data.calibration_required', false);
    }

    public function test_it_allows_repeated_optional_serials_defined_as_non_unique_by_the_schema(): void
    {
        $g = $this->graph();
        foreach ([' shared-sensor ', ' SHARED-SENSOR '] as $serial) {
            $payload = $this->payload();
            $payload['serial_number'] = $serial;
            $this->withToken($g['token'])->postJson('/api/v1/drones/'.$g['drone'].'/sensors', $payload)->assertCreated();
        }
        $this->assertDatabaseCount('drone_sensors', 2);
    }

    public function test_it_validates_the_documented_sensor_fields(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/drones/'.$g['drone'].'/sensors', [
            'sensor_name' => ' ', 'sensor_type' => 'thermal', 'manufacturer' => str_repeat('x', 101),
            'model' => str_repeat('x', 101), 'serial_number' => str_repeat('x', 101),
            'resolution' => str_repeat('x', 81), 'range_meters' => 0,
            'calibration_required' => 'maybe', 'status' => 'retired',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'sensor_name', 'sensor_type', 'manufacturer', 'model', 'serial_number', 'resolution',
            'range_meters', 'calibration_required', 'status',
        ], 'error.details');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_hides_foreign_deleted_missing_and_malformed_parent_drones(): void
    {
        $g = $this->graph();
        foreach ([$g['foreign_drone'], $g['deleted_drone'], (string) Str::uuid(), 'bad'] as $id) {
            $this->withToken($g['token'])->postJson('/api/v1/drones/'.$id.'/sensors', $this->payload())
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $this->assertDatabaseCount('drone_sensors', 0);
    }

    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $g = $this->graph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($g['token'])->postJson('/api/v1/drones/'.$g['drone'].'/sensors', $this->payload())
            ->assertInternalServerError();
        $this->assertDatabaseCount('drone_sensors', 0);
    }

    public function test_it_requires_active_authentication_without_an_invented_permission(): void
    {
        $this->postJson('/api/v1/drones/'.Str::uuid().'/sensors', $this->payload())->assertUnauthorized();
        $g = $this->graph();
        DB::table('users')->where('user_id', $g['actor'])->update(['status' => 'inactive']);
        $this->withToken($g['token'])->postJson('/api/v1/drones/'.$g['drone'].'/sensors', $this->payload())
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_it_rate_limits_sensor_registration(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $url = '/api/v1/drones/'.$g['drone'].'/sensors';
        $this->withToken($g['token'])->postJson($url, $this->payload())->assertCreated();
        $this->withToken($g['token'])->postJson($url, $this->payload())->assertTooManyRequests();
    }

    public function test_it_versions_api_only_insert_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/021_drone_sensor_write_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT INSERT ON TABLE app.drone_sensors TO mangroscan_api_rw;', $dcl);
        $this->assertStringNotContainsString('UPDATE', $dcl);
        $this->assertStringNotContainsString('DELETE', $dcl);
        $this->assertStringNotContainsString('mangroscan_report_ro', $dcl);
        $this->assertStringNotContainsString('mangroscan_worker', $dcl);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'sensor_name' => ' MangroScan RGB Camera ', 'sensor_type' => ' RGB_CAMERA ',
            'manufacturer' => ' MangroScan Labs ', 'model' => ' Vision One ', 'serial_number' => ' sensor-001 ',
            'resolution' => ' 4K ', 'range_meters' => '120.50', 'calibration_required' => true, 'status' => ' ACTIVE ',
        ];
    }

    /** @return array<string, string> */
    private function graph(): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $foreignDrone = (string) Str::uuid();
        $deletedDrone = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => 'Sensor Registry', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => 'Foreign Sensor Registry', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('users')->insert([
            'user_id' => $actor, 'organization_id' => $org, 'first_name' => 'Sensor', 'last_name' => 'Registrar',
            'email' => Str::uuid().'@test', 'password' => Hash::make('password'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->drone($drone, $org, 'Local');
        $this->drone($foreignDrone, $foreignOrg, 'Foreign');
        $this->drone($deletedDrone, $org, 'Deleted', true);

        return [
            'org' => $org, 'actor' => $actor, 'drone' => $drone, 'foreign_drone' => $foreignDrone,
            'deleted_drone' => $deletedDrone, 'token' => User::findOrFail($actor)->createToken('sensor-store')->plainTextToken,
        ];
    }

    private function drone(string $id, string $organizationId, string $name, bool $deleted = false): void
    {
        DB::table('drones')->insert([
            'drone_id' => $id, 'organization_id' => $organizationId, 'drone_name' => $name,
            'status' => 'available', 'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }
}
