<?php

namespace App\Http\Controllers\Api\V1\Drone;

use App\Http\Controllers\Controller;
use App\Http\Requests\Drone\SensorCalibrationStoreRequest;
use App\Http\Resources\SensorCalibrationResource;
use App\Models\DroneSensor;
use App\Models\User;
use App\Services\Drone\SensorCalibrationCreationService;
use Illuminate\Http\JsonResponse;

class SensorCalibrationStoreController extends Controller
{
    // [CAL-01] Record a calibration for a tenant-owned sensor.
    public function __invoke(
        SensorCalibrationStoreRequest $request,
        string $sensor,
        SensorCalibrationCreationService $creation,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $sensorModel = DroneSensor::query()
            ->whereHas('drone', function ($query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            })
            ->findOrFail($sensor);

        $calibration = $creation->create(
            $actor,
            $sensorModel,
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new SensorCalibrationResource($calibration))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], 201);
    }
}
