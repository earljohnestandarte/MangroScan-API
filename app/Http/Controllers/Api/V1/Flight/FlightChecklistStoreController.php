<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Http\Requests\Flight\FlightChecklistStoreRequest;
use App\Http\Resources\FlightChecklistResource;
use App\Models\User;
use App\Services\Flight\FlightChecklistSubmissionService;
use App\Services\Flight\ScopedFlightService;
use Illuminate\Http\JsonResponse;

class FlightChecklistStoreController extends Controller
{
    // [CHK-01] Append pre-flight or post-flight readiness evidence.
    public function __invoke(
        FlightChecklistStoreRequest $request,
        string $flight,
        ScopedFlightService $scoped,
        FlightChecklistSubmissionService $submission,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $flightModel = $scoped->find($actor, $flight);
        $checklist = $submission->submit(
            actor: $actor,
            flight: $flightModel,
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new FlightChecklistResource($checklist))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
