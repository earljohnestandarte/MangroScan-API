<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Http\Requests\Flight\FlightCompleteRequest;
use App\Http\Resources\FlightSessionResource;
use App\Models\User;
use App\Services\Flight\FlightCompletionService;
use App\Services\Flight\ScopedFlightService;
use Illuminate\Http\JsonResponse;

class FlightCompleteController extends Controller
{
    // [FLT-06] Complete one flying tenant flight with its landing summary.
    public function __invoke(
        FlightCompleteRequest $request,
        string $flight,
        ScopedFlightService $scoped,
        FlightCompletionService $completion,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $flightModel = $scoped->find($actor, $flight);
        $completed = $completion->complete(
            actor: $actor,
            flight: $flightModel,
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new FlightSessionResource($completed))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
