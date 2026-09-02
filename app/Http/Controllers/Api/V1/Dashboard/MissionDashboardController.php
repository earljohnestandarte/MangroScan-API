<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Dashboard\MissionDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MissionDashboardController extends Controller
{
    // [DASH-02] Return tenant-scoped mission analytics from canonical results and dashboard views.
    public function __invoke(Request $request, string $mission, MissionDashboardService $dashboard): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $dashboard->get($actor, $mission, $request)]);
    }
}
