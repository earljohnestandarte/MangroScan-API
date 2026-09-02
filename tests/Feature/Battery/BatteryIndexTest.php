<?php

namespace Tests\Feature\Battery;

use App\Models\Battery;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BatteryIndexTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthorizedUser(): User
    {
        $organization = Organization::factory()->create();

        $permission = Permission::query()->firstOrCreate(
            ['permission_code' => 'batteries.read'],
            [
                'permission_name' => 'Read Batteries',
                'description' => 'Allows viewing batteries',
            ]
        );

        $role = Role::query()->create([
            'organization_id' => $organization->organization_id,
            'role_name' => 'Battery Test Role',
            'role_code' => 'BATTERY_TEST_' . uniqid(),
            'description' => 'Role used for battery feature tests',
            'is_system_role' => false,
        ]);

        $role->permissions()->attach($permission->permission_id);

        $user = User::factory()->create([
            'organization_id' => $organization->organization_id,
            'status' => 'active',
        ]);

        $user->roles()->attach($role->role_id);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_it_lists_only_batteries_from_the_actors_organization(): void
    {
        $user = $this->createAuthorizedUser();

        $organization = $user->organization;

        $otherOrganization = Organization::factory()->create();

        Battery::factory()->create([
            'organization_id' => $organization->organization_id,
            'battery_code' => 'BAT-OWN',
        ]);

        Battery::factory()->create([
            'organization_id' => $otherOrganization->organization_id,
            'battery_code' => 'BAT-OTHER',
        ]);

        $response = $this->getJson('/api/v1/batteries');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'battery_id',
                        'battery_code',
                        'battery_type',
                        'capacity_mah',
                        'voltage',
                        'status',
                    ],
                ],
            ])
            ->assertJsonFragment([
                'battery_code' => 'BAT-OWN',
            ])
            ->assertJsonMissing([
                'battery_code' => 'BAT-OTHER',
            ]);
    }

    public function test_it_filters_by_status(): void
    {
        $user = $this->createAuthorizedUser();

        Battery::factory()->create([
            'organization_id' => $user->organization_id,
            'battery_code' => 'BAT-AVAILABLE',
            'status' => 'available',
        ]);

        Battery::factory()->create([
            'organization_id' => $user->organization_id,
            'battery_code' => 'BAT-INUSE',
            'status' => 'in_use',
        ]);

        $response = $this->getJson('/api/v1/batteries?status=available');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'battery_code' => 'BAT-AVAILABLE',
            ])
            ->assertJsonMissing([
                'battery_code' => 'BAT-INUSE',
            ]);
    }

    public function test_it_filters_by_battery_type(): void
    {
        $user = $this->createAuthorizedUser();

        Battery::factory()->create([
            'organization_id' => $user->organization_id,
            'battery_code' => 'BAT-LIPO',
            'battery_type' => 'lipo',
        ]);

        Battery::factory()->create([
            'organization_id' => $user->organization_id,
            'battery_code' => 'BAT-NIMH',
            'battery_type' => 'nimh',
        ]);

        $response = $this->getJson('/api/v1/batteries?type=lipo');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'battery_code' => 'BAT-LIPO',
            ])
            ->assertJsonMissing([
                'battery_code' => 'BAT-NIMH',
            ]);
    }

    public function test_it_supports_pagination(): void
    {
        $user = $this->createAuthorizedUser();

        Battery::factory()
            ->count(3)
            ->create([
                'organization_id' => $user->organization_id,
            ]);

        $response = $this->getJson('/api/v1/batteries?per_page=2');

        $response
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_it_rejects_invalid_filters(): void
    {
        $this->createAuthorizedUser();

        $this->getJson('/api/v1/batteries?status=invalid')
            ->assertUnprocessable();

        $this->getJson('/api/v1/batteries?type=invalid')
            ->assertUnprocessable();
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/batteries')
            ->assertUnauthorized();
    }
}