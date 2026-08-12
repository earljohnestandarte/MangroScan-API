<?php

namespace App\Http\Controllers\Api\V1\Rbac;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rbac\RolePermissionReplaceRequest;
use App\Http\Resources\PermissionResource;
use App\Models\User;
use App\Services\Rbac\RolePermissionReplacementService;
use App\Services\Rbac\ScopedRoleService;
use Illuminate\Http\JsonResponse;

class RolePermissionReplaceController extends Controller
{
    // [RBAC-04] Atomically replace a scoped non-system role's complete permission set.
    public function __invoke(RolePermissionReplaceRequest $request, string $role, ScopedRoleService $scopedRoles, RolePermissionReplacementService $replacement): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = $scopedRoles->find($actor, $role);
        $permissions = $replacement->replace(
            actor: $actor, authorizedRole: $target,
            permissionIds: $request->validated('permission_ids'), ipAddress: $request->ip(),
            userAgent: $request->userAgent(), requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => [
                'role_id' => $target->role_id,
                'permissions' => PermissionResource::collection($permissions)->resolve($request),
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
