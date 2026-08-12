<?php

namespace App\Services\Tenancy;

use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\EffectiveAccessService;
use Illuminate\Auth\Access\AuthorizationException;

class OrganizationScopeService
{
    public function __construct(private readonly EffectiveAccessService $effectiveAccess) {}

    /**
     * @throws AuthorizationException
     */
    public function resolve(User $user, ?string $requestedOrganizationId): string
    {
        if ($requestedOrganizationId === null
            || $requestedOrganizationId === $user->organization_id) {
            return $user->organization_id;
        }

        $permissions = $this->effectiveAccess->rolesAndPermissions($user)['permissions'];

        if (! in_array('organizations.manage', $permissions, true)) {
            throw new AuthorizationException;
        }

        Organization::query()->findOrFail($requestedOrganizationId);

        return $requestedOrganizationId;
    }
}
