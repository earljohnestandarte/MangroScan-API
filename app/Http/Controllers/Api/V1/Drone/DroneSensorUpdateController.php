<?php

namespace App\Http\Controllers\Api\V1\Drone;

use App\Http\Controllers\Controller;
use App\Http\Requests\Drone\DroneSensorUpdateRequest;
use App\Http\Resources\DroneSensorResource;
use App\Models\DroneSensor;
use App\Models\User;
use App\Services\Drone\DroneSensorUpdateService;
use Illuminate\Http\JsonResponse;

class DroneSensorUpdateController extends Controller
{
    // [SENSOR-02] Update a sensor belonging to a tenant-owned drone.
    public function __invoke(
        DroneSensorUpdateRequest $request,
        string $sensor,
        DroneSensorUpdateService $update,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $sensorModel = DroneSensor::query()
            ->whereHas('drone', function ($query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            })
            ->findOrFail($sensor);

        $updated = $update->update(
            $actor,
            $sensorModel,
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new DroneSensorResource($updated))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}