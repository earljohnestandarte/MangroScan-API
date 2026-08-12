<?php

namespace App\Http\Controllers\Api\V1\Drone;

use App\Http\Controllers\Controller;
use App\Http\Resources\DroneResource;
use App\Http\Resources\DroneSensorResource;
use App\Models\Drone;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DroneShowController extends Controller
{
    // [DRONE-03] Return a tenant-owned drone with deterministic sensor detail.
    public function __invoke(Request $request, string $drone): JsonResponse
    {
        /** @var User $actor */ $actor = $request->user();
        $target = Drone::query()->where('organization_id', $actor->organization_id)->findOrFail($drone);
        $sensors = $target->sensors()->orderBy('sensor_name')->orderBy('sensor_id')->get();

        return response()->json([
            'data' => [
                'drone' => (new DroneResource($target))->resolve($request),
                'sensors' => DroneSensorResource::collection($sensors)->resolve($request),
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
