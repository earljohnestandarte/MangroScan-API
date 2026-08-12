<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Http\Requests\Flight\FlightWaypointReplaceRequest;
use App\Models\User;
use App\Services\Flight\FlightWaypointReplacementService;
use App\Services\Flight\ScopedFlightService;
use Illuminate\Http\JsonResponse;

class FlightWaypointReplaceController extends Controller
{
    public function __invoke(FlightWaypointReplaceRequest $request, string $flight, ScopedFlightService $scoped, FlightWaypointReplacementService $replacement): JsonResponse
    {
        /** @var User $actor */ $actor = $request->user();
        $count = $replacement->replace($actor, $scoped->find($actor, $flight), $request->validated('waypoints'), $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));

        return response()->json(['data' => ['count' => $count], 'meta' => ['request_id' => $request->attributes->get('request_id')]]);
    }
}
