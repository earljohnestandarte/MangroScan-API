<?php

namespace Tests\Feature\Drone;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsApiIdentity;
use Tests\TestCase;

class SensorCalibrationIndexTest extends TestCase
{
    use BuildsApiIdentity;
    use RefreshDatabase;

    public function test_it_lists_only_tenant_calibrations_and_supports_filters(): void
    {
        $actor = $this->apiIdentity(['sensor_calibrations.manage'], 'cal-index-');
        $ownSensor = $this->sensor($actor['organization_id'], 'Own sensor');
        $secondSensor = $this->sensor($actor['organization_id'], 'Second sensor');
        $foreignOrganization = $this->organization('Foreign calibration organization');
        $foreignSensor = $this->sensor($foreignOrganization, 'Foreign sensor');

        $ownValid = $this->calibration($ownSensor, '2026-08-30', true);
        $this->calibration($ownSensor, '2026-08-29', false);
        $this->calibration($secondSensor, '2026-08-28', true);
        $foreign = $this->calibration($foreignSensor, '2026-09-01', true);

        $this->withToken($actor['token'])
            ->withHeader('X-Request-ID', 'req_calibration_index')
            ->getJson('/api/v1/sensor-calibrations?sensor_id='.$ownSensor.'&is_valid=1&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.calibration_id', $ownValid)
            ->assertJsonPath('data.0.sensor_id', $ownSensor)
            ->assertJsonPath('data.0.sensor.sensor_name', 'Own sensor')
            ->assertJsonPath('data.0.sensor.drone.drone_name', 'Own sensor drone')
            ->assertJsonPath('data.0.is_valid', true)
            ->assertJsonPath('data.0.has_calibration_file', false)
            ->assertJsonMissingPath('data.0.calibration_file_path')
            ->assertJsonPath('meta.request_id', 'req_calibration_index')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['calibration_id' => $foreign]);
    }

    public function test_it_paginates_newest_calibrations_first(): void
    {
        $actor = $this->apiIdentity(['sensor_calibrations.manage'], 'cal-page-');
        $sensor = $this->sensor($actor['organization_id'], 'Paged sensor');
        $this->calibration($sensor, '2026-08-28', true);
        $newest = $this->calibration($sensor, '2026-08-30', true);
        $this->calibration($sensor, '2026-08-29', false);

        $this->withToken($actor['token'])
            ->getJson('/api/v1/sensor-calibrations?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.calibration_id', $newest)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_it_validates_filters_and_requires_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/sensor-calibrations')->assertUnauthorized();

        $withoutPermission = $this->apiIdentity([], 'cal-denied-');
        $this->withToken($withoutPermission['token'])
            ->getJson('/api/v1/sensor-calibrations')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'sensor_calibrations.manage');

        $this->app['auth']->forgetGuards();

        $actor = $this->apiIdentity(['sensor_calibrations.manage'], 'cal-invalid-');
        $this->withToken($actor['token'])
            ->getJson('/api/v1/sensor-calibrations?sensor_id=bad&is_valid=maybe')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sensor_id', 'is_valid'], 'error.details');
    }

    private function sensor(string $organizationId, string $name): string
    {
        $droneId = (string) Str::uuid();
        $sensorId = (string) Str::uuid();
        DB::table('drones')->insert([
            'drone_id' => $droneId,
            'organization_id' => $organizationId,
            'drone_name' => $name.' drone',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('drone_sensors')->insert([
            'sensor_id' => $sensorId,
            'drone_id' => $droneId,
            'sensor_name' => $name,
            'sensor_type' => 'rgb_camera',
            'calibration_required' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $sensorId;
    }

    private function calibration(string $sensorId, string $date, bool $valid): string
    {
        $id = (string) Str::uuid();
        DB::table('sensor_calibrations')->insert([
            'calibration_id' => $id,
            'sensor_id' => $sensorId,
            'calibration_date' => $date,
            'calibration_method' => 'Reference target',
            'calibration_notes' => 'Verified in a controlled test.',
            'is_valid' => $valid,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
