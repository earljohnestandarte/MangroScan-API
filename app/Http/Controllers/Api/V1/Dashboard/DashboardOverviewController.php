<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DashboardOverviewRequest;
use App\Models\User;
use App\Services\Dashboard\DashboardOverviewService;
use Illuminate\Http\JsonResponse;

class DashboardOverviewController extends Controller
{
    // [DASH-01] Return role- and tenant-scoped KPI groups from the dashboard snapshot.
    public function __invoke(DashboardOverviewRequest $request, DashboardOverviewService $dashboard): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $dashboard->get($actor, $request->validated())]);
    }
}
