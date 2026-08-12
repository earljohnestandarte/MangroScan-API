<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\SitePlotStoreRequest;
use App\Http\Resources\MonitoringPlotResource;
use App\Models\User;
use App\Services\Site\MonitoringPlotCreationService;
use App\Services\Site\ScopedSurveySiteService;
use Illuminate\Http\JsonResponse;

class SitePlotStoreController extends Controller
{
    // [PLOT-02] Create one validation plot in a tenant-visible site.
    public function __invoke(SitePlotStoreRequest $request, string $site, ScopedSurveySiteService $scopedSites, MonitoringPlotCreationService $creation): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $plot = $creation->create(
            $actor, $scopedSites->find($actor, $site), $request->validated(),
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new MonitoringPlotResource($plot))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
