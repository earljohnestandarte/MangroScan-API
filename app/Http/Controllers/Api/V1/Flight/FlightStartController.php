<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Http\Requests\Flight\FlightStartRequest;
use App\Http\Resources\FlightSessionResource;
use App\Models\User;
use App\Services\Flight\FlightStartService;
use App\Services\Flight\ScopedFlightService;
use Illuminate\Http\JsonResponse;

class FlightStartController extends Controller
{
    // [FLT-05] Start one planned flight after its latest passed pre-flight checklist.
    public function __invoke(
        FlightStartRequest $request,
        string $flight,
        ScopedFlightService $scoped,
        FlightStartService $start,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $flightModel = $scoped->find($actor, $flight);
        $started = $start->start(
            actor: $actor,
            flight: $flightModel,
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new FlightSessionResource($started))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
