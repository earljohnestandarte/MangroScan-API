<?php

namespace App\Services\Drone;

use App\Exceptions\WorkflowConflictException;
use App\Models\Drone;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class DroneUpdateService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function update(
        User $actor,
        Drone $drone,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): Drone {
        return DB::transaction(function () use (
            $actor,
            $drone,
            $data,
            $ipAddress,
            $userAgent,
            $requestId,
        ): Drone {
            $current = Drone::query()
                ->where('organization_id', $actor->organization_id)
                ->lockForUpdate()
                ->findOrFail($drone->drone_id);

            if (array_key_exists('serial_number', $data)) {
                $serialNumber = $data['serial_number'];

                if ($serialNumber !== null && $serialNumber !== $current->serial_number) {
                    if (DB::getDriverName() === 'pgsql') {
                        DB::select(
                            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                            [$serialNumber]
                        );
                    }

                    if (
                        Drone::withTrashed()
                            ->where('serial_number', $serialNumber)
                            ->where('drone_id', '!=', $current->drone_id)
                            ->exists()
                    ) {
                        throw new WorkflowConflictException(
                            'A drone with this serial number already exists.',
                            ['serial_number' => $serialNumber],
                        );
                    }
                }
            }

            $fields = [
                'drone_name',
                'model',
                'serial_number',
                'firmware_version',
                'max_flight_minutes',
                'payload_capacity_grams',
                'status',
            ];

            $old = $current->only($fields);

            $current->fill($data)->save();
            $current->refresh();

            $this->auditLogger->record(
                action: 'drone.update',
                tableName: 'drones',
                recordId: $current->drone_id,
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