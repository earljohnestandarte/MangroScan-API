<?php

namespace Database\Factories;

use App\Models\Battery;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Battery>
 */
class BatteryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'battery_code' => 'BAT-' . fake()->unique()->numerify('#####'),
            'battery_type' => fake()->randomElement([
                'lipo',
                'li-ion',
                'nimh',
            ]),
            'capacity_mah' => fake()->randomFloat(2, 1000, 10000),
            'voltage' => fake()->randomFloat(2, 7, 30),
            'status' => 'available',
        ];
    }
}