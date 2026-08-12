<?php

namespace App\Services\Drone;

use App\Models\Drone;
use App\Models\DroneSensor;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class DroneSensorCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, Drone $drone, array $data, ?string $ipAddress, ?string $userAgent, ?string $requestId): DroneSensor
    {
        return DB::transaction(function () use ($actor, $drone, $data, $ipAddress, $userAgent, $requestId): DroneSensor {
            $sensor = $drone->sensors()->create([
                'sensor_name' => $data['sensor_name'],
                'sensor_type' => $data['sensor_type'],
                'manufacturer' => $data['manufacturer'] ?? null,
                'model' => $data['model'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'resolution' => $data['resolution'] ?? null,
                'range_meters' => $data['range_meters'] ?? null,
                'calibration_required' => $data['calibration_required'],
                'status' => $data['status'],
            ]);

            $this->auditLogger->record(
                action: 'sensor.create', tableName: 'drone_sensors', recordId: $sensor->sensor_id,
                userId: $actor->user_id, oldValues: null,
                newValues: $sensor->only(['drone_id', 'sensor_name', 'sensor_type', 'manufacturer', 'model', 'serial_number', 'resolution', 'range_meters', 'calibration_required', 'status']),
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return $sensor;
        });
    }
}
