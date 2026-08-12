<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Http\Requests\Flight\FlightUpdateRequest;
use App\Http\Resources\FlightSessionResource;
use App\Models\User;
use App\Services\Flight\FlightUpdateService;
use App\Services\Flight\ScopedFlightService;
use Illuminate\Http\JsonResponse;

class FlightUpdateController extends Controller
{
    // [FLT-04] Update planning metadata for a tenant flight.
    public function __invoke(
        FlightUpdateRequest $request,
        string $flight,
        ScopedFlightService $scoped,
        FlightUpdateService $update,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $updated = $update->update(
            $actor, $scoped->find($actor, $flight), $request->validated(),
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new FlightSessionResource($updated))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
