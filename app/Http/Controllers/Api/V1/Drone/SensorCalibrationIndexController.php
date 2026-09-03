<?php

namespace App\Http\Controllers\Api\V1\Drone;

use App\Http\Controllers\Controller;
use App\Http\Requests\Drone\SensorCalibrationIndexRequest;
use App\Http\Resources\SensorCalibrationResource;
use App\Models\SensorCalibration;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SensorCalibrationIndexController extends Controller
{
    // [CAL-02] List calibration history for sensors owned by the actor's tenant.
    public function __invoke(SensorCalibrationIndexRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = SensorCalibration::query()
            ->with(['sensor.drone'])
            ->whereHas('sensor.drone', function ($query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            });

        if (! empty($validated['sensor_id'])) {
            $query->where('sensor_id', $validated['sensor_id']);
        }

        if (array_key_exists('is_valid', $validated) && $validated['is_valid'] !== null) {
            $query->where('is_valid', (bool) $validated['is_valid']);
        }

        $calibrations = $query
            ->orderByDesc('calibration_date')
            ->orderByDesc('created_at')
            ->orderBy('calibration_id')
            ->paginate($perPage);

        return response()->json([
            'data' => SensorCalibrationResource::collection(collect($calibrations->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $calibrations->currentPage(),
                'per_page' => $calibrations->perPage(),
                'total' => $calibrations->total(),
                'last_page' => $calibrations->lastPage(),
            ],
        ]);
    }
}
