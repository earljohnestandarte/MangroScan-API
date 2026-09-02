<?php

namespace App\Http\Controllers\Api\V1\Drone;

use App\Http\Controllers\Controller;
use App\Http\Requests\Drone\DroneUpdateRequest;
use App\Http\Resources\DroneResource;
use App\Models\User;
use App\Services\Drone\DroneUpdateService;
use App\Services\Drone\ScopedDroneService;
use Illuminate\Http\JsonResponse;

class DroneUpdateController extends Controller
{
    // [DRONE-04] Update metadata/status for a tenant-owned drone.
    public function __invoke(
        DroneUpdateRequest $request,
        string $drone,
        ScopedDroneService $scoped,
        DroneUpdateService $update,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $updated = $update->update(
            $actor,
            $scoped->find($actor, $drone),
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new DroneResource($updated))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}