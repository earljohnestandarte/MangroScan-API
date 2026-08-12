<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\SiteBoundaryStoreRequest;
use App\Http\Resources\SiteBoundaryResource;
use App\Models\User;
use App\Services\Site\ScopedSurveySiteService;
use App\Services\Site\SiteBoundaryCreationService;
use Illuminate\Http\JsonResponse;

class SiteBoundaryStoreController extends Controller
{
    // [BOUND-02] Create one valid polygon inside a tenant-visible survey site.
    public function __invoke(
        SiteBoundaryStoreRequest $request,
        string $site,
        ScopedSurveySiteService $scopedSites,
        SiteBoundaryCreationService $boundaryCreation,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $surveySite = $scopedSites->find($actor, $site);
        $boundary = $boundaryCreation->create(
            actor: $actor,
            site: $surveySite,
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new SiteBoundaryResource($boundary))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], 201);
    }
}
