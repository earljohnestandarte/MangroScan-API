<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\OrganizationUpdateRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\User;
use App\Services\Organization\OrganizationUpdateService;
use Illuminate\Http\JsonResponse;

class OrganizationUpdateController extends Controller
{
    // [ORG-04] Update or deactivate an organization with immutable before/after evidence.
    public function __invoke(
        OrganizationUpdateRequest $request,
        string $organization,
        OrganizationUpdateService $organizationUpdate,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $organization = $organizationUpdate->update(
            actor: $actor,
            organizationId: $organization,
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new OrganizationResource($organization))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
