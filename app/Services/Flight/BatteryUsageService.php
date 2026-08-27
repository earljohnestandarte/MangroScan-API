<?php

namespace App\Services\Flight;

use App\Exceptions\WorkflowConflictException;
use App\Models\Battery;
use App\Models\BatteryUsage;
use App\Models\FlightSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class BatteryUsageService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function record(
        User $actor,
        FlightSession $flight,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): BatteryUsage {
        return DB::transaction(function () use (
            $actor,
            $flight,
            $data,
            $ipAddress,
            $userAgent,
            $requestId,
        ): BatteryUsage {
            /*
             * The controller already resolves the flight through
             * ScopedFlightService. Do not perform a second, different
             * relationship lookup here.
             */
            $currentFlight = FlightSession::query()
                ->where('flight_session_id', $flight->flight_session_id)
                ->lockForUpdate()
                ->first();

            if (! $currentFlight instanceof FlightSession) {
                throw (new ModelNotFoundException)
                    ->setModel(
                        FlightSession::class,
                        [$flight->flight_session_id]
                    );
            }

            $battery = Battery::query()
                ->where('organization_id', $actor->organization_id)
                ->lockForUpdate()
                ->find($data['battery_id']);

            if (! $battery instanceof Battery) {
                throw (new ModelNotFoundException)
                    ->setModel(
                        Battery::class,
                        [$data['battery_id']]
                    );
            }

            if ($battery->status === 'retired') {
                throw new WorkflowConflictException(
                    'The selected battery is retired.',
                    ['battery_id' => $battery->battery_id],
                );
            }

            if ($data['end_percentage'] > $data['start_percentage']) {
                throw new WorkflowConflictException(
                    'Battery end percentage cannot exceed start percentage.',
                    [
                        'start_percentage' => $data['start_percentage'],
                        'end_percentage' => $data['end_percentage'],
                    ],
                );
            }

            $usage = BatteryUsage::query()->create([
                'flight_session_id' => $currentFlight->flight_session_id,
                'battery_id' => $battery->battery_id,
                'start_percentage' => $data['start_percentage'],
                'end_percentage' => $data['end_percentage'],
                'usage_minutes' => $data['usage_minutes'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $battery->update([
                'status' => 'available',
            ]);

            $this->auditLogger->record(
                action: 'battery_usage.create',
                tableName: 'battery_usages',
                recordId: $usage->battery_usage_id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: $usage->only([
                    'battery_usage_id',
                    'flight_session_id',
                    'battery_id',
                    'start_percentage',
                    'end_percentage',
                    'usage_minutes',
                    'notes',
                ]),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $usage->refresh();
        });
    }
}