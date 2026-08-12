<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserUpdateRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\User\ScopedUserService;
use App\Services\User\UserUpdateService;
use Illuminate\Http\JsonResponse;

class UserUpdateController extends Controller
{
    // [USR-04] Update a scoped safe profile with immutable before/after evidence.
    public function __invoke(
        UserUpdateRequest $request,
        string $user,
        ScopedUserService $scopedUsers,
        UserUpdateService $userUpdate,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $target = $scopedUsers->find($actor, $user);
        $target = $userUpdate->update(
            actor: $actor,
            authorizedTarget: $target,
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new UserResource($target))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
