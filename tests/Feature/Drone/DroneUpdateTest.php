<?php

namespace Tests\Feature\Drone;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DroneUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_a_tenant_owned_drone_with_audit_evidence(): void
    {
        $g = $this->graph();

        $response = $this->withToken($g['token'])
            ->withHeader('X-Request-ID', 'req_drone_04')
            ->patchJson('/api/v1/drones/'.$g['drone'], [
                'drone_name' => ' Updated Drone ',
                'model' => ' Mavic 3 Enterprise ',
                'serial_number' => ' updated-serial-001 ',
                'firmware_version' => ' 2.0.0 ',
                'max_flight_minutes' => '45.50',
                'payload_capacity_grams' => '900.25',
                'status' => ' maintenance ',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.drone_id', $g['drone'])
            ->assertJsonPath('data.organization_id', $g['org'])
            ->assertJsonPath('data.drone_name', 'Updated Drone')
            ->assertJsonPath('data.model', 'Mavic 3 Enterprise')
            ->assertJsonPath('data.serial_number', 'UPDATED-SERIAL-001')
            ->assertJsonPath('data.firmware_version', '2.0.0')
            ->assertJsonPath('data.max_flight_minutes', '45.50')
            ->assertJsonPath('data.payload_capacity_grams', '900.25')
            ->assertJsonPath('data.status', 'maintenance')
            ->assertJsonPath('meta.request_id', 'req_drone_04');

        $this->assertDatabaseHas('drones', [
            'drone_id' => $g['drone'],
            'organization_id' => $g['org'],
            'drone_name' => 'Updated Drone',
            'serial_number' => 'UPDATED-SERIAL-001',
            'status' => 'maintenance',
        ]);

        $audit = AuditLog::query()->sole();

        $this->assertSame('drone.update', $audit->action);
        $this->assertSame($g['drone'], $audit->record_id);
        $this->assertSame('OLD-SERIAL-001', $audit->old_values['serial_number']);
        $this->assertSame('UPDATED-SERIAL-001', $audit->new_values['serial_number']);
    }

    public function test_it_rejects_updates_to_foreign_drones(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->patchJson('/api/v1/drones/'.$g['foreign_drone'], [
                'drone_name' => 'Hacked Drone',
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertDatabaseHas('drones', [
            'drone_id' => $g['foreign_drone'],
            'drone_name' => 'Foreign Drone',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_rejects_updates_to_deleted_drones(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->patchJson('/api/v1/drones/'.$g['deleted_drone'], [
                'drone_name' => 'Should Not Update',
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_requires_at_least_one_field(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->patchJson('/api/v1/drones/'.$g['drone'], [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['request'], 'error.details');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_validates_update_fields(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->patchJson('/api/v1/drones/'.$g['drone'], [
                'drone_name' => ' ',
                'serial_number' => str_repeat('x', 101),
                'firmware_version' => str_repeat('x', 81),
                'max_flight_minutes' => 0,
                'payload_capacity_grams' => 0,
                'status' => 'flying',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'drone_name',
                'serial_number',
                'firmware_version',
                'max_flight_minutes',
                'payload_capacity_grams',
                'status',
            ], 'error.details');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_rejects_a_serial_number_already_used_by_another_drone(): void
    {
        $g = $this->graph();

        $this->withToken($g['token'])
            ->patchJson('/api/v1/drones/'.$g['drone'], [
                'serial_number' => 'OTHER-SERIAL-001',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'CONFLICT');

        $this->assertDatabaseHas('drones', [
            'drone_id' => $g['drone'],
            'serial_number' => 'OLD-SERIAL-001',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_requires_authentication_and_manage_permission(): void
    {
        $this->patchJson('/api/v1/drones/'.Str::uuid(), [
            'drone_name' => 'Updated',
        ])->assertUnauthorized();

        $g = $this->graph();

        DB::table('role_permissions')->delete();
        $this->app['auth']->forgetGuards();

        $this->withToken($g['token'])
            ->patchJson('/api/v1/drones/'.$g['drone'], [
                'drone_name' => 'Updated',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'drones.manage');
    }

    /** @return array<string, string> */
    private function graph(): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();

       $drone = (string) Str::uuid();
$otherDrone = (string) Str::uuid();
$foreignDrone = (string) Str::uuid();
$deletedDrone = (string) Str::uuid();

        DB::table('organizations')->insert([
            [
                'organization_id' => $org,
                'organization_name' => 'Drone Update',
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
            'first_name' => 'Drone',
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
            'role_name' => 'Drone Manager',
            'role_code' => 'drone_manager',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permissions')->insert([
            'permission_id' => $permission,
            'permission_code' => 'drones.manage',
            'permission_name' => 'Manage drones',
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
        'drone_name' => 'Primary Drone',
        'model' => 'Old Model',
        'serial_number' => 'OLD-SERIAL-001',
        'firmware_version' => '1.0.0',
        'max_flight_minutes' => '40.00',
        'payload_capacity_grams' => '800.00',
        'status' => 'available',
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => null,
    ],
    [
        'drone_id' => $otherDrone,
        'organization_id' => $org,
        'drone_name' => 'Other Drone',
        'model' => 'Other Model',
        'serial_number' => 'OTHER-SERIAL-001',
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
        'model' => 'Foreign Model',
        'serial_number' => 'FOREIGN-SERIAL-001',
        'firmware_version' => '1.0.0',
        'max_flight_minutes' => '40.00',
        'payload_capacity_grams' => '800.00',
        'status' => 'available',
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => null,
    ],
    [
        'drone_id' => $deletedDrone,
        'organization_id' => $org,
        'drone_name' => 'Deleted Drone',
        'model' => 'Deleted Model',
        'serial_number' => 'DELETED-SERIAL-001',
        'firmware_version' => '1.0.0',
        'max_flight_minutes' => '40.00',
        'payload_capacity_grams' => '800.00',
        'status' => 'retired',
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => now(),
    ],
]);

        return [
            'org' => $org,
            'actor' => $actor,
            'drone' => $drone,
            'foreign_drone' => $foreignDrone,
            'deleted_drone' => $deletedDrone,
            'role' => $role,
            'permission' => $permission,
            'token' => User::findOrFail($actor)
                ->createToken('drone-update')
                ->plainTextToken,
        ];
    }
}