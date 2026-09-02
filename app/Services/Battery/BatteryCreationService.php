<?php

namespace App\Services\Battery;

use App\Exceptions\WorkflowConflictException;
use App\Models\Battery;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class BatteryCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(
        User $actor,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): Battery {
        return DB::transaction(function () use ($actor, $data, $ipAddress, $userAgent, $requestId): Battery {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$data['battery_code']]);
            }

            if (Battery::withTrashed()->where('battery_code', $data['battery_code'])->exists()) {
                throw new WorkflowConflictException(
                    'A battery with this code already exists.',
                    ['battery_code' => $data['battery_code']],
                );
            }

            $battery = Battery::query()->create($data + [
                'organization_id' => $actor->organization_id,
            ]);

            $this->auditLogger->record(
                action: 'battery.create',
                tableName: 'batteries',
                recordId: $battery->battery_id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: $battery->only([
                    'battery_id', 'organization_id', 'battery_code', 'battery_type',
                    'capacity_mah', 'voltage', 'status',
                ]),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $battery->refresh();
        });
    }
}
