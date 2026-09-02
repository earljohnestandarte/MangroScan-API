<?php

namespace App\Services\Drone;

use App\Exceptions\WorkflowConflictException;
use App\Models\DroneSensor;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class DroneSensorUpdateService
{
    public function __construct(
        private readonly AuditLogger $auditLogger
    ) {}

    /** @param array<string, mixed> $data */
    public function update(
        User $actor,
        DroneSensor $sensor,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): DroneSensor {
        return DB::transaction(function () use (
            $actor,
            $sensor,
            $data,
            $ipAddress,
            $userAgent,
            $requestId,
        ): DroneSensor {
            $current = DroneSensor::query()
                ->whereHas('drone', function ($query) use ($actor): void {
                    $query->where('organization_id', $actor->organization_id);
                })
                ->lockForUpdate()
                ->findOrFail($sensor->sensor_id);

            if (array_key_exists('serial_number', $data)) {
                $serialNumber = $data['serial_number'];

                if ($serialNumber !== null && $serialNumber !== $current->serial_number) {
                    if (
                        DroneSensor::query()
                            ->where('serial_number', $serialNumber)
                            ->where('sensor_id', '!=', $current->sensor_id)
                            ->exists()
                    ) {
                        throw new WorkflowConflictException(
                            'A sensor with this serial number already exists.',
                            ['serial_number' => $serialNumber],
                        );
                    }
                }
            }

            $fields = [
                'drone_id',
                'sensor_name',
                'sensor_type',
                'manufacturer',
                'model',
                'serial_number',
                'resolution',
                'range_meters',
                'calibration_required',
                'status',
            ];

            $old = $current->only($fields);

            $current->fill($data)->save();
            $current->refresh();

            $this->auditLogger->record(
                action: 'sensor.update',
                tableName: 'drone_sensors',
                recordId: $current->sensor_id,
                userId: $actor->user_id,
                oldValues: $old,
                newValues: $current->only($fields),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $current;
        });
    }
}
