<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DeveloperUserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $password = (string) config('mangroscan.seed_user_password');

        if ($password === '') {
            throw new RuntimeException('MANGROSCAN_SEED_USER_PASSWORD must be configured before seeding developer users.');
        }

        foreach (RbacSeedData::USERS as $email => $definition) {
            $roleId = Role::query()
                ->where('organization_id', RbacSeedData::ORGANIZATION_ID)
                ->where('role_code', $definition['role'])
                ->value('role_id')
                ?? throw new RuntimeException("Seed role [{$definition['role']}] does not exist.");

            $user = User::withTrashed()->where('email', $email)->first() ?? new User;

            if (! $user->exists) {
                $user->user_id = $definition['id'];
            }

            $user->fill([
                'organization_id' => RbacSeedData::ORGANIZATION_ID,
                'first_name' => $definition['first_name'],
                'middle_name' => null,
                'last_name' => $definition['last_name'],
                'position_title' => $definition['position_title'],
                'email' => $email,
                'status' => 'active',
            ]);
            $user->email_verified_at = $user->email_verified_at ?? now();
            $user->deleted_at = null;

            if (! $user->exists || ! Hash::check($password, (string) $user->password)) {
                $user->password = Hash::make($password);
            }

            $user->save();
            $user->roles()->sync([$roleId]);
        }
    }
}
