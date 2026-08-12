<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Http\Requests\Flight\FlightFailRequest;
use App\Http\Resources\FlightSessionResource;
use App\Models\User;
use App\Services\Flight\FlightFailureService;
use App\Services\Flight\ScopedFlightService;
use Illuminate\Http\JsonResponse;

class FlightFailController extends Controller
{
    // [FLT-07] Abort or fail one active tenant flight.
    public function __invoke(FlightFailRequest $request, string $flight, ScopedFlightService $scoped, FlightFailureService $failure): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $failed = $failure->fail(
            $actor, $scoped->find($actor, $flight), $request->validated(),
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new FlightSessionResource($failed))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
