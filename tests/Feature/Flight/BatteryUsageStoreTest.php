<?php

namespace Tests\Feature\Flight;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BatteryUsageStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_battery_usage_for_a_flight(): void
    {
        $g = $this->graph();

        $response = $this->withToken($g['token'])
            ->postJson('/api/v1/flights/'.$g['flight'].'/battery-usage', [
                'battery_id' => $g['battery'],
                'start_percentage' => 100,
                'end_percentage' => 65,
                'usage_minutes' => 25,
                'notes' => 'Normal sortie battery usage',
            ]);



$response->assertCreated()            ->assertJsonPath('data.flight_session_id', $g['flight'])
            ->assertJsonPath('data.battery_id', $g['battery'])
            ->assertJsonPath('data.start_percentage', '100.00')
            ->assertJsonPath('data.end_percentage', '65.00')
            ->assertJsonPath('data.usage_minutes', '25.00')
            ->assertJsonPath('data.notes', 'Normal sortie battery usage');

        $this->assertDatabaseHas('battery_usages', [
            'flight_session_id' => $g['flight'],
            'battery_id' => $g['battery'],
        ]);
    }

    public function test_it_validates_battery_usage(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->postJson('/api/v1/flights/'.$g['flight'].'/battery-usage', [
                'battery_id' => 'bad',
                'start_percentage' => 150,
                'end_percentage' => -1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'battery_id',
                'start_percentage',
                'end_percentage',
            ], 'error.details');
    }

    public function test_it_requires_authentication_and_permission(): void
    {
        $g = $this->graph(false);

        $this->postJson('/api/v1/flights/'.$g['flight'].'/battery-usage', [
            'battery_id' => $g['battery'],
            'start_percentage' => 100,
            'end_percentage' => 50,
        ])->assertUnauthorized();

        $this->withToken($g['token'])
            ->postJson('/api/v1/flights/'.$g['flight'].'/battery-usage', [
                'battery_id' => $g['battery'],
                'start_percentage' => 100,
                'end_percentage' => 50,
            ])
            ->assertForbidden();
    }

    private function graph(bool $permission = true): array
    {
        $org = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $flight = (string) Str::uuid();
        $battery = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();

        DB::table('organizations')->insert([
            'organization_id' => $org,
            'organization_name' => 'Battery Test Org',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'user_id' => $userId,
            'organization_id' => $org,
            'first_name' => 'Battery',
            'last_name' => 'Tester',
            'email' => Str::uuid().'@test',
            'password' => Hash::make('password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($permission) {
           $permissionId = DB::table('permissions')
    ->where('permission_code', 'flights.update')
    ->value('permission_id') ?? (string) Str::uuid();

if (! DB::table('permissions')->where('permission_id', $permissionId)->exists()) {
    DB::table('permissions')->insert([
        'permission_id' => $permissionId,
        'permission_code' => 'flights.update',
        'permission_name' => 'Update flights',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

            $role = (string) Str::uuid();

            DB::table('roles')->insert([
                'role_id' => $role,
                'organization_id' => $org,
                'role_name' => 'Battery Tester',
                'role_code' => 'battery-tester-'.Str::lower(Str::random(8)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('role_permissions')->insert([
                'role_id' => $role,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('user_roles')->insert([
                'user_id' => $userId,
                'role_id' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('batteries')->insert([
            'battery_id' => $battery,
            'organization_id' => $org,
            'battery_code' => 'BAT-'.Str::upper(Str::random(8)),
            'battery_type' => 'lipo',
            'capacity_mah' => 5000,
            'voltage' => 22.2,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('drones')->insert([
            'drone_id' => $drone,
            'organization_id' => $org,
            'drone_name' => 'Battery Test Drone',
            'serial_number' => 'DRONE-'.Str::upper(Str::random(8)),
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('survey_sites')->insert([
            'site_id' => $site,
            'organization_id' => $org,
            'site_name' => 'Battery Test Site',
            'site_code' => 'SITE-'.Str::upper(Str::random(8)),
            'province' => 'P',
            'city_municipality' => 'C',
            'environment_type' => 'coastal',
            'status' => 'active',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('survey_missions')->insert([
            'mission_id' => $mission,
            'site_id' => $site,
            'mission_code' => 'MSN-'.Str::upper(Str::random(8)),
            'mission_title' => 'Battery Test Mission',
            'mission_objective' => 'Test battery usage',
            'mission_status' => 'planned',
            'created_by' => $userId,
            'approved_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('flight_sessions')->insert([
            'flight_session_id' => $flight,
            'mission_id' => $mission,
            'drone_id' => $drone,
            'pilot_user_id' => $userId,
            'flight_code' => 'FLT-'.Str::upper(Str::random(8)),
            'planned_altitude_meters' => 50,
            'flight_status' => 'completed',
            'quality_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'token' => User::query()
                ->findOrFail($userId)
                ->createToken('battery-test')
                ->plainTextToken,
            'flight' => $flight,
            'battery' => $battery,
        ];
    }
}