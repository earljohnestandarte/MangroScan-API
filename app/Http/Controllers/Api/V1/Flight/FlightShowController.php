<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Http\Resources\FlightChecklistResource;
use App\Http\Resources\FlightSessionResource;
use App\Models\User;
use App\Services\Flight\ScopedFlightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlightShowController extends Controller
{
    // [FLT-03] Return one tenant-visible sortie with readiness evidence and child counts.
    public function __invoke(Request $request, string $flight, ScopedFlightService $scoped): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $model = $scoped->find($actor, $flight);
        $checklists = $model->checklists()
            ->orderBy('created_at')
            ->orderBy('checklist_id')
            ->get();
        $counts = $scoped->childCounts($model);

        return response()->json([
            'data' => [
                'flight' => (new FlightSessionResource($model))->resolve($request),
                'checklists' => FlightChecklistResource::collection($checklists)->resolve($request),
                'waypoint_count' => $counts['waypoint_count'],
                'media_count' => $counts['media_count'],
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
