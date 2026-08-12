<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteBoundaryResource;
use App\Models\SiteBoundary;
use App\Models\User;
use App\Services\Site\ScopedSurveySiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteBoundaryIndexController extends Controller
{
    // [BOUND-01] Return the polygons belonging to one tenant-visible survey site.
    public function __invoke(
        Request $request,
        string $site,
        ScopedSurveySiteService $scopedSites,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $surveySite = $scopedSites->find($actor, $site);
        $boundaries = SiteBoundary::query()
            ->withBoundaryGeoJson()
            ->where('site_id', $surveySite->site_id)
            ->orderBy('boundary_name')
            ->orderBy('boundary_id')
            ->get();

        return response()->json([
            'data' => SiteBoundaryResource::collection($boundaries)->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
