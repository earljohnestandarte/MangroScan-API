<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Http\Requests\Flight\FlightStoreRequest;
use App\Http\Resources\FlightSessionResource;
use App\Models\User;
use App\Services\Flight\FlightCreationService;
use App\Services\Mission\ScopedMissionService;
use Illuminate\Http\JsonResponse;

class MissionFlightStoreController extends Controller
{
    // [FLT-02] Create one planned sortie for an approved tenant mission.
    public function __invoke(
        FlightStoreRequest $request,
        string $mission,
        ScopedMissionService $scoped,
        FlightCreationService $creation,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $missionModel = $scoped->find($actor, $mission);
        $flight = $creation->create(
            actor: $actor,
            mission: $missionModel,
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new FlightSessionResource($flight))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
