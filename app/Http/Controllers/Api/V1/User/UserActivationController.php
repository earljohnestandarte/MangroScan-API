<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserActivationRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\User\ScopedUserService;
use App\Services\User\UserActivationService;
use Illuminate\Http\JsonResponse;

class UserActivationController extends Controller
{
    // [USR-05] Activate or deactivate a scoped account with audit and session revocation.
    public function __invoke(
        UserActivationRequest $request,
        string $user,
        ScopedUserService $scopedUsers,
        UserActivationService $userActivation,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $target = $scopedUsers->find($actor, $user);
        $target = $userActivation->setActive(
            actor: $actor,
            authorizedTarget: $target,
            isActive: $request->boolean('is_active'),
            reason: $request->validated('reason'),
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
