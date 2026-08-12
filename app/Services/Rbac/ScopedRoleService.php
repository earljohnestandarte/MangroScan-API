<?php

namespace App\Services\Rbac;

use App\Models\Role;
use App\Models\User;
use App\Services\Auth\EffectiveAccessService;

class ScopedRoleService
{
    public function __construct(private readonly EffectiveAccessService $effectiveAccess) {}

    public function find(User $actor, string $roleId): Role
    {
        $permissions = $this->effectiveAccess->rolesAndPermissions($actor)['permissions'];

        return Role::query()
            ->where(function ($query) use ($actor, $permissions): void {
                $query->where('organization_id', $actor->organization_id);

                if (in_array('organizations.manage', $permissions, true)) {
                    $query->orWhereNull('organization_id');
                }
            })
            ->findOrFail($roleId);
    }
}
