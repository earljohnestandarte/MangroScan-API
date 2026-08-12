<?php

namespace App\Services\User;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, position_title?: string|null, roles: list<string>}  $data
     *
     * @throws ValidationException
     */
    public function create(
        User $actor,
        string $organizationId,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): User {
        $roles = Role::query()
            ->whereIn('role_id', $data['roles'])
            ->where(function ($query) use ($organizationId): void {
                $query
                    ->whereNull('organization_id')
                    ->orWhere('organization_id', $organizationId);
            })
            ->orderBy('role_name')
            ->get();

        if ($roles->count() !== count($data['roles'])) {
            throw ValidationException::withMessages([
                'roles' => ['One or more roles are unavailable in the target organization.'],
            ]);
        }

        return DB::transaction(function () use (
            $actor,
            $organizationId,
            $data,
            $roles,
            $ipAddress,
            $userAgent,
            $requestId,
        ): User {
            $user = User::query()->create([
                'organization_id' => $organizationId,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'position_title' => $data['position_title'] ?? null,
                'email' => $data['email'],
                'password' => Str::password(64),
                'status' => 'active',
            ]);
            $roleIds = $roles->pluck('role_id')->all();
            $user->roles()->sync($roleIds);

            $this->auditLogger->record(
                action: 'user.create',
                tableName: 'users',
                recordId: $user->user_id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: [
                    'organization_id' => $organizationId,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'position_title' => $user->position_title,
                    'email' => $user->email,
                    'status' => $user->status,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );
            $this->auditLogger->record(
                action: 'role.assign',
                tableName: 'user_roles',
                recordId: $user->user_id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: ['role_ids' => $roleIds],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $user->refresh();
        });
    }
}
