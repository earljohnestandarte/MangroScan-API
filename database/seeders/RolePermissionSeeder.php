<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use RuntimeException;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissionIds = Permission::query()
            ->whereIn('permission_code', array_keys(RbacSeedData::PERMISSIONS))
            ->pluck('permission_id', 'permission_code');

        foreach (RbacSeedData::ROLE_PERMISSIONS as $roleCode => $permissionCodes) {
            $role = Role::query()
                ->where('organization_id', RbacSeedData::ORGANIZATION_ID)
                ->where('role_code', $roleCode)
                ->firstOrFail();

            $ids = collect($permissionCodes)->map(function (string $code) use ($permissionIds): string {
                return $permissionIds[$code]
                    ?? throw new RuntimeException("Seed permission [{$code}] does not exist.");
            });

            $role->permissions()->sync($ids->all());
        }
    }
}
