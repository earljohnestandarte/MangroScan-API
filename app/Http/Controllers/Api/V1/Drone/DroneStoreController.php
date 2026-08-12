<?php

namespace App\Http\Controllers\Api\V1\Drone;

use App\Http\Controllers\Controller;
use App\Http\Requests\Drone\DroneStoreRequest;
use App\Http\Resources\DroneResource;
use App\Models\User;
use App\Services\Drone\DroneCreationService;
use Illuminate\Http\JsonResponse;

class DroneStoreController extends Controller
{
    // [DRONE-02] Register a tenant-owned drone with immutable audit evidence.
    public function __invoke(DroneStoreRequest $request, DroneCreationService $creation): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $drone = $creation->create($actor, $request->validated(), $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));

        return response()->json([
            'data' => (new DroneResource($drone))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
