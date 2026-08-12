<?php

namespace App\Services\Rbac;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserRoleReplacementService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<string>  $roleIds
     * @return Collection<int, Role>
     *
     * @throws ValidationException
     */
    public function replace(
        User $actor,
        User $target,
        array $roleIds,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): Collection {
        $roles = Role::query()
            ->whereIn('role_id', $roleIds)
            ->where(function ($query) use ($target): void {
                $query
                    ->whereNull('organization_id')
                    ->orWhere('organization_id', $target->organization_id);
            })
            ->orderBy('role_name')
            ->orderBy('role_id')
            ->get();

        if ($roles->count() !== count($roleIds)) {
            throw ValidationException::withMessages([
                'role_ids' => ['One or more roles are unavailable for the target user.'],
            ]);
        }

        return DB::transaction(function () use (
            $actor,
            $target,
            $roles,
            $ipAddress,
            $userAgent,
            $requestId,
        ): Collection {
            $oldRoleIds = $target->roles()
                ->orderBy('roles.role_id')
                ->pluck('roles.role_id')
                ->all();
            $newRoleIds = $roles->pluck('role_id')->sort()->values()->all();

            $target->roles()->sync($newRoleIds);

            $this->auditLogger->record(
                action: 'role.assign',
                tableName: 'user_roles',
                recordId: $target->user_id,
                userId: $actor->user_id,
                oldValues: ['role_ids' => $oldRoleIds],
                newValues: ['role_ids' => $newRoleIds],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $roles;
        });
    }
}
