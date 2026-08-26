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

class SensorCalibrationStoreTest extends TestCase
{
    use RefreshDatabase;

    // [CAL-01] Record a calibration with immutable audit evidence.
    public function test_it_registers_a_sensor_calibration_with_audit_evidence(): void
    {
        $g = $this->graph();

        $response = $this->withToken($g['token'])
            ->withHeader('X-Request-ID', 'req_cal_01')
            ->postJson(
                '/api/v1/sensors/'.$g['sensor'].'/calibrations',
                $this->payload()
            );

        $response->assertCreated()
            ->assertJsonPath('data.sensor_id', $g['sensor'])
            ->assertJsonPath('data.calibration_date', '2026-08-26')
            ->assertJsonPath('data.calibration_method', 'Manufacturer calibration')
            ->assertJsonPath('data.calibration_file_path', 'calibrations/sensor-001.pdf')
            ->assertJsonPath('data.calibration_notes', 'Calibration passed all required checks.')
            ->assertJsonPath('data.is_valid', true)
            ->assertJsonPath('meta.request_id', 'req_cal_01');

        $this->assertSame(
            [
                'calibration_id',
                'sensor_id',
                'calibration_date',
                'calibration_method',
                'calibration_file_path',
                'calibration_notes',
                'is_valid',
                'created_at',
                'updated_at',
            ],
            array_keys($response->json('data')),
        );

        $this->assertDatabaseHas('sensor_calibrations', [
            'calibration_id' => $response->json('data.calibration_id'),
            'sensor_id' => $g['sensor'],
            'calibration_method' => 'Manufacturer calibration',
            'calibration_file_path' => 'calibrations/sensor-001.pdf',
            'is_valid' => true,
        ]);

        $audit = AuditLog::query()->sole();

        $this->assertSame('sensor_calibration.create', $audit->action);
        $this->assertSame(
            $response->json('data.calibration_id'),
            $audit->record_id
        );
        $this->assertSame($g['sensor'], $audit->new_values['sensor_id']);
        $this->assertSame(
            'Manufacturer calibration',
            $audit->new_values['calibration_method']
        );
    }

    public function test_it_accepts_omitted_optional_fields(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->postJson(
                '/api/v1/sensors/'.$g['sensor'].'/calibrations',
                [
                    'calibration_date' => '2026-08-26',
                    'calibration_method' => 'Field calibration',
                    'is_valid' => false,
                ]
            )
            ->assertCreated()
            ->assertJsonPath('data.calibration_file_path', null)
            ->assertJsonPath('data.calibration_notes', null)
            ->assertJsonPath('data.is_valid', false);
    }

    public function test_it_normalizes_calibration_text_fields(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->postJson(
                '/api/v1/sensors/'.$g['sensor'].'/calibrations',
                [
                    'calibration_date' => '2026-08-26',
                    'calibration_method' => '  Manufacturer calibration  ',
                    'calibration_file_path' => '  calibrations/sensor-001.pdf  ',
                    'calibration_notes' => '  Calibration completed.  ',
                    'is_valid' => true,
                ]
            )
            ->assertCreated()
            ->assertJsonPath(
                'data.calibration_method',
                'Manufacturer calibration'
            )
            ->assertJsonPath(
                'data.calibration_file_path',
                'calibrations/sensor-001.pdf'
            )
            ->assertJsonPath(
                'data.calibration_notes',
                'Calibration completed.'
            );
    }

    public function test_it_validates_the_documented_calibration_fields(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->postJson(
                '/api/v1/sensors/'.$g['sensor'].'/calibrations',
                [
                    'calibration_date' => 'not-a-date',
                    'calibration_method' => ' ',
                    'calibration_file_path' => str_repeat('x', 501),
                    'is_valid' => 'maybe',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'calibration_date',
                'calibration_method',
                'calibration_file_path',
                'is_valid',
            ], 'error.details');

        $this->assertDatabaseCount('sensor_calibrations', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_requires_the_required_calibration_fields(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->postJson(
                '/api/v1/sensors/'.$g['sensor'].'/calibrations',
                []
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'calibration_date',
                'calibration_method',
                'is_valid',
            ], 'error.details');

        $this->assertDatabaseCount('sensor_calibrations', 0);
    }

    public function test_it_hides_foreign_missing_and_malformed_sensors(): void
    {
        $g = $this->graph();

        foreach (
            [
                $g['foreign_sensor'],
                (string) Str::uuid(),
                'bad',
            ] as $id
        ) {
            $this->withToken($g['token'])
                ->postJson(
                    '/api/v1/sensors/'.$id.'/calibrations',
                    $this->payload()
                )
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }

        $this->assertDatabaseCount('sensor_calibrations', 0);
    }

    public function test_it_requires_active_authentication_and_calibration_permission(): void
    {
        $this->postJson(
            '/api/v1/sensors/'.Str::uuid().'/calibrations',
            $this->payload()
        )->assertUnauthorized();

        $g = $this->graph();

        DB::table('role_permissions')->delete();

        $this->app['auth']->forgetGuards();

        $this->withToken($g['token'])
            ->postJson(
                '/api/v1/sensors/'.$g['sensor'].'/calibrations',
                $this->payload()
            )
            ->assertForbidden()
            ->assertJsonPath(
                'error.details.required_permission',
                'sensor_calibrations.manage'
            );

        DB::table('role_permissions')->insert([
            'role_id' => $g['role'],
            'permission_id' => $g['permission'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')
            ->where('user_id', $g['actor'])
            ->update(['status' => 'inactive']);

        $this->app['auth']->forgetGuards();

        $this->withToken($g['token'])
            ->postJson(
                '/api/v1/sensors/'.$g['sensor'].'/calibrations',
                $this->payload()
            )
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $g = $this->graph();

        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('down'));

        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($g['token'])
            ->postJson(
                '/api/v1/sensors/'.$g['sensor'].'/calibrations',
                $this->payload()
            )
            ->assertInternalServerError();

        $this->assertDatabaseCount('sensor_calibrations', 0);
    }

    public function test_it_rate_limits_calibration_registration(): void
    {
        config([
            'mangroscan.auth.authenticated_requests_per_minute' => 1,
        ]);

        $g = $this->graph();

        $url = '/api/v1/sensors/'.$g['sensor'].'/calibrations';

        $this->withToken($g['token'])
            ->postJson($url, $this->payload())
            ->assertCreated();

        $this->withToken($g['token'])
            ->postJson($url, $this->payload())
            ->assertTooManyRequests();
    }

    public function test_it_versions_api_with_insert_only_calibration_dcl(): void
    {
        $dcl = file_get_contents(
            database_path('sql/dcl/046_sensor_calibration_write_grants.sql')
        );

        $this->assertIsString($dcl);

        $this->assertStringContainsString(
            'GRANT INSERT ON TABLE app.sensor_calibrations TO mangroscan_api_rw;',
            $dcl
        );

        $this->assertStringNotContainsString('UPDATE', $dcl);
        $this->assertStringNotContainsString('DELETE', $dcl);
        $this->assertStringNotContainsString('mangroscan_report_ro', $dcl);
        $this->assertStringNotContainsString('mangroscan_worker', $dcl);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'calibration_date' => '2026-08-26',
            'calibration_method' => '  Manufacturer calibration  ',
            'calibration_file_path' => '  calibrations/sensor-001.pdf  ',
            'calibration_notes' => '  Calibration passed all required checks.  ',
            'is_valid' => true,
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

        $sensor = (string) Str::uuid();
        $foreignSensor = (string) Str::uuid();

        DB::table('organizations')->insert([
            [
                'organization_id' => $org,
                'organization_name' => 'Calibration Registry',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => $foreignOrg,
                'organization_name' => 'Foreign Calibration Registry',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('users')->insert([
            'user_id' => $actor,
            'organization_id' => $org,
            'first_name' => 'Calibration',
            'last_name' => 'Registrar',
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
            'role_name' => 'Calibration Manager',
            'role_code' => 'calibration_manager',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permissions')->insert([
            'permission_id' => $permission,
            'permission_code' => 'sensor_calibrations.manage',
            'permission_name' => 'Manage sensor calibrations',
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

        $this->drone($drone, $org, 'Local Drone');
        $this->drone($foreignDrone, $foreignOrg, 'Foreign Drone');

        $this->sensor($sensor, $drone, 'Local Sensor');
        $this->sensor($foreignSensor, $foreignDrone, 'Foreign Sensor');

        return [
            'org' => $org,
            'actor' => $actor,
            'drone' => $drone,
            'sensor' => $sensor,
            'foreign_sensor' => $foreignSensor,
            'role' => $role,
            'permission' => $permission,
            'token' => User::findOrFail($actor)
                ->createToken('sensor-calibration-store')
                ->plainTextToken,
        ];
    }

    private function drone(
        string $id,
        string $organizationId,
        string $name
    ): void {
        DB::table('drones')->insert([
            'drone_id' => $id,
            'organization_id' => $organizationId,
            'drone_name' => $name,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }

    private function sensor(
        string $id,
        string $droneId,
        string $name
    ): void {
        DB::table('drone_sensors')->insert([
            'sensor_id' => $id,
            'drone_id' => $droneId,
            'sensor_name' => $name,
            'sensor_type' => 'rgb_camera',
            'manufacturer' => 'MangroScan Labs',
            'model' => 'Vision One',
            'serial_number' => strtoupper(Str::uuid()),
            'resolution' => '4K',
            'range_meters' => 120.50,
            'calibration_required' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}