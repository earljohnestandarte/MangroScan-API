<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Http\Requests\Flight\BatteryUsageStoreRequest;
use App\Http\Resources\BatteryUsageResource;
use App\Models\User;
use App\Services\Flight\BatteryUsageService;
use App\Services\Flight\ScopedFlightService;
use Illuminate\Http\JsonResponse;

class BatteryUsageStoreController extends Controller
{
    public function __invoke(
        BatteryUsageStoreRequest $request,
        string $flight,
        ScopedFlightService $scoped,
        BatteryUsageService $service,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $flightSession = $scoped->find($actor, $flight);

        $usage = $service->record(
            actor: $actor,
            flight: $flightSession,
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new BatteryUsageResource($usage))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], 201);
    }
}