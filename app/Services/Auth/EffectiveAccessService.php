<?php

namespace App\Services\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EffectiveAccessService
{
    /**
     * @return array{roles: list<string>, permissions: list<string>}
     */
    public function rolesAndPermissions(User $user): array
    {
        $user->loadMissing('roles');

        $scopedRoles = $user->roles->filter(
            fn (Role $role): bool => $role->organization_id === null
                || $role->organization_id === $user->organization_id,
        );

        return [
            'roles' => $scopedRoles
                ->pluck('role_name')
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'permissions' => $scopedRoles
                ->isEmpty()
                    ? []
                    : DB::table('v_user_effective_permissions')
                        ->where('user_id', $user->user_id)
                        ->orderBy('permission_code')
                        ->distinct()
                        ->pluck('permission_code')
                        ->all(),
        ];
    }

    /**
     * @return array{user: array<string, mixed>, organization: array<string, mixed>, roles: list<string>, permissions: list<string>}
     */
    public function authenticatedProfile(User $user): array
    {
        $user->loadMissing('organization');
        $access = $this->rolesAndPermissions($user);

        return [
            'user' => [
                'user_id' => $user->user_id,
                'organization_id' => $user->organization_id,
                'first_name' => $user->first_name,
                'middle_name' => $user->middle_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'status' => $user->status,
            ],
            'organization' => [
                'organization_id' => $user->organization->organization_id,
                'organization_name' => $user->organization->organization_name,
                'organization_type' => $user->organization->organization_type,
                'contact_email' => $user->organization->contact_email,
                'contact_number' => $user->organization->contact_number,
                'address' => $user->organization->address,
                'status' => $user->organization->status,
            ],
            ...$access,
        ];
    }
}
