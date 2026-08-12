<?php

namespace App\Http\Controllers\Api\V1\Rbac;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rbac\UserRoleReplaceRequest;
use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Services\Rbac\UserRoleReplacementService;
use App\Services\User\ScopedUserService;
use Illuminate\Http\JsonResponse;

class UserRoleReplaceController extends Controller
{
    // [RBAC-03] Atomically replace a scoped user's complete role set.
    public function __invoke(
        UserRoleReplaceRequest $request,
        string $user,
        ScopedUserService $scopedUsers,
        UserRoleReplacementService $roleReplacement,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $target = $scopedUsers->find($actor, $user);
        $roles = $roleReplacement->replace(
            actor: $actor,
            target: $target,
            roleIds: $request->validated('role_ids'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => [
                'user_id' => $target->user_id,
                'roles' => RoleResource::collection($roles)->resolve($request),
            ],
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
