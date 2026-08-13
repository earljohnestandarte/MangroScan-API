<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RbacSeedData::ROLES as $code => $definition) {
            $role = Role::query()
                ->where('organization_id', RbacSeedData::ORGANIZATION_ID)
                ->where('role_code', $code)
                ->first() ?? new Role;

            if (! $role->exists) {
                $role->role_id = $definition['id'];
            }

            $role->fill([
                'organization_id' => RbacSeedData::ORGANIZATION_ID,
                'role_code' => $code,
                'role_name' => $definition['name'],
                'description' => $definition['description'],
                'is_system_role' => true,
            ])->save();
        }
    }
}
