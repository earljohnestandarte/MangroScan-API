<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\MonitoringPlotResource;
use App\Models\MonitoringPlot;
use App\Models\User;
use App\Services\Site\ScopedSurveySiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SitePlotIndexController extends Controller
{
    // [PLOT-01] List monitoring plots for one tenant-visible site.
    public function __invoke(Request $request, string $site, ScopedSurveySiteService $scopedSites): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $surveySite = $scopedSites->find($actor, $site);
        $plots = MonitoringPlot::query()
            ->withPlotGeoJson()
            ->where('site_id', $surveySite->site_id)
            ->orderBy('plot_code')
            ->orderBy('plot_id')
            ->get();

        return response()->json([
            'data' => MonitoringPlotResource::collection($plots)->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
