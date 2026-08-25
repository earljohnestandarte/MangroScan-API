<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\EffectiveAccessService;
use Database\Seeders\RbacSeedData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class VerifyDeveloperQaUsers extends Command
{
    protected $signature = 'mangroscan:qa-users:verify';

    protected $description = 'Verify the non-production role-specific browser-QA identities without exposing credentials';

    public function handle(EffectiveAccessService $effectiveAccess): int
    {
        if (app()->environment('production')) {
            $this->error('Developer QA identity verification is disabled in production.');

            return self::FAILURE;
        }

        $password = (string) config('mangroscan.seed_user_password');
        if ($password === '') {
            $this->error('MANGROSCAN_SEED_USER_PASSWORD is not configured.');

            return self::FAILURE;
        }

        $organization = Organization::withTrashed()->find(RbacSeedData::ORGANIZATION_ID);
        $organizationReady = $organization !== null
            && $organization->deleted_at === null
            && $organization->status === 'active';
        $rows = [];
        $failed = ! $organizationReady;

        foreach (RbacSeedData::USERS as $email => $definition) {
            $user = User::withTrashed()->where('email', $email)->first();
            $ready = $organizationReady
                && $user !== null
                && $user->organization_id === RbacSeedData::ORGANIZATION_ID
                && $user->deleted_at === null
                && $user->status === 'active'
                && $user->email_verified_at !== null
                && Hash::check($password, (string) $user->password);

            if ($ready) {
                $access = $effectiveAccess->rolesAndPermissions($user);
                $expectedPermissions = RbacSeedData::ROLE_PERMISSIONS[$definition['role']];
                sort($expectedPermissions);
                $ready = $access['roles'] === [RbacSeedData::ROLES[$definition['role']]['name']]
                    && $access['permissions'] === $expectedPermissions;
            }

            $failed = $failed || ! $ready;
            $rows[] = [$email, RbacSeedData::ROLES[$definition['role']]['name'], $ready ? 'ready' : 'not ready'];
        }

        $this->table(['QA email', 'Expected role', 'Status'], $rows);

        if ($failed) {
            $this->error('QA identities are incomplete or drifted. Run php artisan db:seed, then verify again.');

            return self::FAILURE;
        }

        $this->info('All role-specific browser-QA identities are ready.');

        return self::SUCCESS;
    }
}
