<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\PlotUpdateRequest;
use App\Http\Resources\MonitoringPlotResource;
use App\Models\User;
use App\Services\Site\MonitoringPlotUpdateService;
use App\Services\Site\ScopedMonitoringPlotService;
use Illuminate\Http\JsonResponse;

class PlotUpdateController extends Controller
{
    // [PLOT-03] Update or soft-archive a validation plot.
    public function __invoke(PlotUpdateRequest $request, string $plot, ScopedMonitoringPlotService $scoped, MonitoringPlotUpdateService $updater): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = $scoped->find($actor, $plot);
        $target = $updater->update($actor, $target, $request->validated(), $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));

        return response()->json([
            'data' => (new MonitoringPlotResource($target))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
