<?php

namespace App\Http\Controllers\Api\V1\Rbac;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Models\User;
use App\Services\Rbac\ScopedRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RolePermissionShowController extends Controller
{
    // [RBAC-05] Return a scoped role's complete current permission set.
    public function __invoke(Request $request, string $role, ScopedRoleService $scopedRoles): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = $scopedRoles->find($actor, $role);
        $permissions = $target->permissions()
            ->orderBy('permissions.permission_code')
            ->orderBy('permissions.permission_id')
            ->get();

        return response()->json([
            'data' => [
                'role_id' => $target->role_id,
                'permissions' => PermissionResource::collection($permissions)->resolve($request),
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
