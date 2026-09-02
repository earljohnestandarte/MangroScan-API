<?php

namespace App\Services\Drone;

use App\Models\DroneSensor;
use App\Models\SensorCalibration;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class SensorCalibrationCreationService
{
    public function __construct(
        private readonly AuditLogger $auditLogger
    ) {}

    /** @param array<string, mixed> $data */
    public function create(
        User $actor,
        DroneSensor $sensor,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): SensorCalibration {
        return DB::transaction(function () use (
            $actor,
            $sensor,
            $data,
            $ipAddress,
            $userAgent,
            $requestId,
        ): SensorCalibration {
            $current = DroneSensor::query()
                ->whereHas('drone', function ($query) use ($actor): void {
                    $query->where('organization_id', $actor->organization_id);
                })
                ->findOrFail($sensor->sensor_id);

            $calibration = $current->calibrations()->create([
                'calibration_date' => $data['calibration_date'],
                'calibration_method' => $data['calibration_method'],
                'calibration_file_path' => $data['calibration_file_path'] ?? null,
                'calibration_notes' => $data['calibration_notes'] ?? null,
                'is_valid' => $data['is_valid'],
            ]);

            $this->auditLogger->record(
                action: 'sensor_calibration.create',
                tableName: 'sensor_calibrations',
                recordId: $calibration->calibration_id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: $calibration->only([
                    'calibration_id',
                    'sensor_id',
                    'calibration_date',
                    'calibration_method',
                    'calibration_file_path',
                    'calibration_notes',
                    'is_valid',
                ]),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $calibration;
        });
    }
}
