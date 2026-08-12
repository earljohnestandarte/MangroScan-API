<?php

namespace App\Services\Rbac;

use App\Exceptions\WorkflowConflictException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RolePermissionReplacementService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<string>  $permissionIds
     * @return Collection<int, Permission>
     */
    public function replace(User $actor, Role $authorizedRole, array $permissionIds, ?string $ipAddress, ?string $userAgent, ?string $requestId): Collection
    {
        $permissions = Permission::query()->whereIn('permission_id', $permissionIds)
            ->orderBy('permission_code')->orderBy('permission_id')->get();

        if ($permissions->count() !== count($permissionIds)) {
            throw ValidationException::withMessages([
                'permission_ids' => ['One or more permissions are unavailable.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $authorizedRole, $permissions, $ipAddress, $userAgent, $requestId): Collection {
            $role = Role::query()->lockForUpdate()->findOrFail($authorizedRole->role_id);

            if ($role->is_system_role) {
                throw new WorkflowConflictException(
                    'System role permissions cannot be changed.',
                    ['role_id' => $role->role_id],
                );
            }

            $oldPermissionIds = $role->permissions()->orderBy('permissions.permission_id')
                ->pluck('permissions.permission_id')->all();
            $newPermissionIds = $permissions->pluck('permission_id')->sort()->values()->all();
            $role->permissions()->sync($newPermissionIds);

            $this->auditLogger->record(
                action: 'role.permissions.replace', tableName: 'role_permissions', recordId: $role->role_id,
                userId: $actor->user_id, oldValues: ['permission_ids' => $oldPermissionIds],
                newValues: ['permission_ids' => $newPermissionIds], ipAddress: $ipAddress,
                userAgent: $userAgent, requestId: $requestId,
            );

            return $permissions;
        });
    }
}
