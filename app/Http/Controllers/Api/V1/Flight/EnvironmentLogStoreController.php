<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Http\Requests\Flight\EnvironmentLogStoreRequest;
use App\Http\Resources\EnvironmentLogResource;
use App\Models\User;
use App\Services\Flight\EnvironmentLogCreationService;
use App\Services\Flight\ScopedFlightService;
use Illuminate\Http\JsonResponse;

class EnvironmentLogStoreController extends Controller
{
    // [ENV-01] Append an environment observation to a tenant-owned flight.
    public function __invoke(
        EnvironmentLogStoreRequest $request,
        string $flight,
        ScopedFlightService $scoped,
        EnvironmentLogCreationService $creation,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $flightSession = $scoped->find($actor, $flight);

        $log = $creation->create(
            $actor,
            $flightSession,
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new EnvironmentLogResource($log))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], 201);
    }
}
