<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserStoreRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Tenancy\OrganizationScopeService;
use App\Services\User\UserCreationService;
use Illuminate\Http\JsonResponse;

class UserStoreController extends Controller
{
    // [USR-02] Create a managed user and atomically assign authorized roles.
    public function __invoke(
        UserStoreRequest $request,
        OrganizationScopeService $organizationScope,
        UserCreationService $userCreation,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $organizationId = $organizationScope->resolve($actor, $validated['organization_id']);
        $user = $userCreation->create(
            actor: $actor,
            organizationId: $organizationId,
            data: $validated,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new UserResource($user))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], 201);
    }
}
