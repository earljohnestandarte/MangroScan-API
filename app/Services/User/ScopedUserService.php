<?php

namespace App\Services\User;

use App\Models\User;
use App\Services\Auth\EffectiveAccessService;

class ScopedUserService
{
    public function __construct(private readonly EffectiveAccessService $effectiveAccess) {}

    public function find(User $actor, string $userId): User
    {
        $query = User::query();
        $permissions = $this->effectiveAccess->rolesAndPermissions($actor)['permissions'];

        if (! in_array('organizations.manage', $permissions, true)) {
            $query->where('organization_id', $actor->organization_id);
        }

        return $query->findOrFail($userId);
    }
}
