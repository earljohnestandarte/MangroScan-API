<?php

namespace Tests\Feature\Drone;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DroneSensorUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_a_tenant_owned_sensor_with_audit_evidence(): void
    {
        $g = $this->graph();

        $response = $this->withToken($g['token'])
            ->withHeader('X-Request-ID', 'req_sensor_02')
            ->patchJson('/api/v1/sensors/'.$g['sensor'], [
                'sensor_name' => ' Updated Sensor ',
                'sensor_type' => ' LIDAR ',
                'manufacturer' => ' Updated Manufacturer ',
                'model' => ' Updated Model ',
                'serial_number' => ' updated-sensor-002 ',
                'resolution' => ' 2048x2048 ',
                'range_meters' => '120.50',
                'calibration_required' => true,
                'status' => ' MAINTENANCE ',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.sensor_id', $g['sensor'])
            ->assertJsonPath('data.drone_id', $g['drone'])
            ->assertJsonPath('data.sensor_name', 'Updated Sensor')
            ->assertJsonPath('data.sensor_type', 'lidar')
            ->assertJsonPath('data.manufacturer', 'Updated Manufacturer')
            ->assertJsonPath('data.model', 'Updated Model')
            ->assertJsonPath('data.serial_number', 'UPDATED-SENSOR-002')
            ->assertJsonPath('data.resolution', '2048x2048')
            ->assertJsonPath('data.range_meters', '120.50')
            ->assertJsonPath('data.calibration_required', true)
            ->assertJsonPath('data.status', 'maintenance')
            ->assertJsonPath('meta.request_id', 'req_sensor_02');

        $this->assertDatabaseHas('drone_sensors', [
            'sensor_id' => $g['sensor'],
            'drone_id' => $g['drone'],
            'sensor_name' => 'Updated Sensor',
            'sensor_type' => 'lidar',
            'serial_number' => 'UPDATED-SENSOR-002',
            'status' => 'maintenance',
        ]);

        $audit = AuditLog::query()->sole();

        $this->assertSame('sensor.update', $audit->action);
        $this->assertSame($g['sensor'], $audit->record_id);
        $this->assertSame('OLD-SENSOR-001', $audit->old_values['serial_number']);
        $this->assertSame('UPDATED-SENSOR-002', $audit->new_values['serial_number']);
    }

    public function test_it_rejects_updates_to_a_foreign_sensor(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->patchJson('/api/v1/sensors/'.$g['foreign_sensor'], [
                'sensor_name' => 'Hacked Sensor',
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertDatabaseHas('drone_sensors', [
            'sensor_id' => $g['foreign_sensor'],
            'sensor_name' => 'Foreign Sensor',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_requires_at_least_one_field(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->patchJson('/api/v1/sensors/'.$g['sensor'], [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['request'], 'error.details');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_validates_update_fields(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->patchJson('/api/v1/sensors/'.$g['sensor'], [
                'sensor_name' => ' ',
                'sensor_type' => 'invalid',
                'serial_number' => str_repeat('x', 101),
                'resolution' => str_repeat('x', 81),
                'range_meters' => 0,
                'calibration_required' => 'invalid',
                'status' => 'broken',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'sensor_name',
                'sensor_type',
                'serial_number',
                'resolution',
                'range_meters',
                'calibration_required',
                'status',
            ], 'error.details');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_rejects_a_duplicate_serial_number(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->patchJson('/api/v1/sensors/'.$g['sensor'], [
                'serial_number' => 'OTHER-SENSOR-001',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'CONFLICT');

        $this->assertDatabaseHas('drone_sensors', [
            'sensor_id' => $g['sensor'],
            'serial_number' => 'OLD-SENSOR-001',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_requires_authentication_and_manage_permission(): void
    {
        $this->patchJson('/api/v1/sensors/'.Str::uuid(), [
            'sensor_name' => 'Updated',
        ])->assertUnauthorized();

        $g = $this->graph();

        DB::table('role_permissions')->delete();
        $this->app['auth']->forgetGuards();

        $this->withToken($g['token'])
            ->patchJson('/api/v1/sensors/'.$g['sensor'], [
                'sensor_name' => 'Updated',
            ])
            ->assertForbidden()
            ->assertJsonPath(
                'error.details.required_permission',
                'sensors.manage'
            );
    }

    /** @return array<string, string> */
    private function graph(): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();

        $drone = (string) Str::uuid();
        $foreignDrone = (string) Str::uuid();

        $sensor = (string) Str::uuid();
        $otherSensor = (string) Str::uuid();
        $foreignSensor = (string) Str::uuid();

        DB::table('organizations')->insert([
            [
                'organization_id' => $org,
                'organization_name' => 'Sensor Update',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => $foreignOrg,
                'organization_name' => 'Foreign Organization',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('users')->insert([
            'user_id' => $actor,
            'organization_id' => $org,
            'first_name' => 'Sensor',
            'last_name' => 'Updater',
            'email' => Str::uuid().'@test',
            'password' => Hash::make('password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $role = (string) Str::uuid();
        $permission = (string) Str::uuid();

        DB::table('roles')->insert([
            'role_id' => $role,
            'organization_id' => $org,
            'role_name' => 'Sensor Manager',
            'role_code' => 'sensor_manager',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permissions')->insert([
            'permission_id' => $permission,
            'permission_code' => 'sensors.manage',
            'permission_name' => 'Manage sensors',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_roles')->insert([
            'user_id' => $actor,
            'role_id' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_permissions')->insert([
            'role_id' => $role,
            'permission_id' => $permission,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('drones')->insert([
            [
                'drone_id' => $drone,
                'organization_id' => $org,
                'drone_name' => 'Sensor Drone',
                'model' => 'Mavic 3',
                'serial_number' => 'DRONE-SENSOR-001',
                'firmware_version' => '1.0.0',
                'max_flight_minutes' => '40.00',
                'payload_capacity_grams' => '800.00',
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'drone_id' => $foreignDrone,
                'organization_id' => $foreignOrg,
                'drone_name' => 'Foreign Drone',
                'model' => 'Foreign',
                'serial_number' => 'FOREIGN-DRONE-001',
                'firmware_version' => '1.0.0',
                'max_flight_minutes' => '40.00',
                'payload_capacity_grams' => '800.00',
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        ]);

        DB::table('drone_sensors')->insert([
            [
                'sensor_id' => $sensor,
                'drone_id' => $drone,
                'sensor_name' => 'Primary Sensor',
                'sensor_type' => 'rgb_camera',
                'manufacturer' => 'Old Manufacturer',
                'model' => 'Old Model',
                'serial_number' => 'OLD-SENSOR-001',
                'resolution' => '1920x1080',
                'range_meters' => '50.00',
                'calibration_required' => false,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'sensor_id' => $otherSensor,
                'drone_id' => $drone,
                'sensor_name' => 'Other Sensor',
                'sensor_type' => 'lidar',
                'manufacturer' => 'Other',
                'model' => 'Other',
                'serial_number' => 'OTHER-SENSOR-001',
                'resolution' => null,
                'range_meters' => '100.00',
                'calibration_required' => false,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'sensor_id' => $foreignSensor,
                'drone_id' => $foreignDrone,
                'sensor_name' => 'Foreign Sensor',
                'sensor_type' => 'gps',
                'manufacturer' => 'Foreign',
                'model' => 'Foreign',
                'serial_number' => 'FOREIGN-SENSOR-001',
                'resolution' => null,
                'range_meters' => '100.00',
                'calibration_required' => false,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return [
            'org' => $org,
            'actor' => $actor,
            'drone' => $drone,
            'sensor' => $sensor,
            'foreign_sensor' => $foreignSensor,
            'role' => $role,
            'permission' => $permission,
            'token' => User::findOrFail($actor)
                ->createToken('sensor-update')
                ->plainTextToken,
        ];
    }
}
