<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\SurveySiteResource;
use App\Models\User;
use App\Services\Site\ScopedSurveySiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteShowController extends Controller
{
    // [SITE-03] Return one tenant-scoped site and its stable child-resource counts.
    public function __invoke(
        Request $request,
        string $site,
        ScopedSurveySiteService $scopedSites,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $surveySite = $scopedSites->find($actor, $site);

        return response()->json([
            'data' => [
                'site' => (new SurveySiteResource($surveySite))->resolve($request),
                'counts' => $scopedSites->summaryCounts($surveySite),
            ],
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
