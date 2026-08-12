<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\SiteStoreRequest;
use App\Http\Resources\SurveySiteResource;
use App\Models\User;
use App\Services\Site\SurveySiteCreationService;
use Illuminate\Http\JsonResponse;

class SiteStoreController extends Controller
{
    // [SITE-02] Create a site inside the caller's organization with mandatory audit evidence.
    public function __invoke(
        SiteStoreRequest $request,
        SurveySiteCreationService $siteCreation,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $site = $siteCreation->create(
            actor: $actor,
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new SurveySiteResource($site))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], 201);
    }
}
