<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\OrganizationStoreRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\User;
use App\Services\Organization\OrganizationCreationService;
use Illuminate\Http\JsonResponse;

class OrganizationStoreController extends Controller
{
    // [ORG-02] Create an organization with mandatory immutable audit evidence.
    public function __invoke(
        OrganizationStoreRequest $request,
        OrganizationCreationService $organizationCreation,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $organization = $organizationCreation->create(
            actor: $actor,
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
        ], 201);
    }
}
