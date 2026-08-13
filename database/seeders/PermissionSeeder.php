<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RbacSeedData::PERMISSIONS as $code => $name) {
            Permission::query()->updateOrCreate(
                ['permission_code' => $code],
                [
                    'permission_name' => $name,
                    'description' => "Allows the assigned role to {$name}.",
                ],
            );
        }
    }
}
