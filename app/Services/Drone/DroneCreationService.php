<?php

namespace App\Services\Drone;

use App\Exceptions\WorkflowConflictException;
use App\Models\Drone;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class DroneCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data, ?string $ipAddress, ?string $userAgent, ?string $requestId): Drone
    {
        return DB::transaction(function () use ($actor, $data, $ipAddress, $userAgent, $requestId): Drone {
            $serialNumber = $data['serial_number'] ?? null;
            if ($serialNumber !== null) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$serialNumber]);
                }
                if (Drone::withTrashed()->where('serial_number', $serialNumber)->exists()) {
                    throw new WorkflowConflictException('A drone with this serial number already exists.', ['serial_number' => $serialNumber]);
                }
            }

            $drone = Drone::query()->create([
                'organization_id' => $actor->organization_id,
                'drone_name' => $data['drone_name'],
                'model' => $data['model'] ?? null,
                'serial_number' => $serialNumber,
                'firmware_version' => $data['firmware_version'] ?? null,
                'max_flight_minutes' => $data['max_flight_minutes'] ?? null,
                'payload_capacity_grams' => $data['payload_capacity_grams'] ?? null,
                'status' => $data['status'],
            ]);

            $this->auditLogger->record(
                action: 'drone.create', tableName: 'drones', recordId: $drone->drone_id,
                userId: $actor->user_id, oldValues: null,
                newValues: $drone->only(['organization_id', 'drone_name', 'model', 'serial_number', 'firmware_version', 'max_flight_minutes', 'payload_capacity_grams', 'status']),
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return $drone;
        });
    }
}
