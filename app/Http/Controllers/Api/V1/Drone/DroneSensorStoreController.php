<?php

namespace App\Http\Controllers\Api\V1\Drone;

use App\Http\Controllers\Controller;
use App\Http\Requests\Drone\DroneSensorStoreRequest;
use App\Http\Resources\DroneSensorResource;
use App\Models\User;
use App\Services\Drone\DroneSensorCreationService;
use App\Services\Drone\ScopedDroneService;
use Illuminate\Http\JsonResponse;

class DroneSensorStoreController extends Controller
{
    // [SENSOR-01] Attach one sensor to a tenant-owned drone.
    public function __invoke(DroneSensorStoreRequest $request, string $drone, ScopedDroneService $scopedDrones, DroneSensorCreationService $creation): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $sensor = $creation->create(
            $actor, $scopedDrones->find($actor, $drone), $request->validated(),
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new DroneSensorResource($sensor))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
