<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\User\ScopedUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserShowController extends Controller
{
    // [USR-03] Return a scoped safe user profile and its effective role resources.
    public function __invoke(Request $request, string $user, ScopedUserService $scopedUsers): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = $scopedUsers->find($actor, $user);
        $target->load('roles');
        $roles = $target->roles
            ->filter(fn (Role $role): bool => $role->organization_id === null
                || $role->organization_id === $target->organization_id)
            ->sortBy([
                ['role_name', 'asc'],
                ['role_id', 'asc'],
            ])
            ->values();

        return response()->json([
            'data' => [
                'user' => (new UserResource($target))->resolve($request),
                'roles' => RoleResource::collection($roles)->resolve($request),
            ],
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
