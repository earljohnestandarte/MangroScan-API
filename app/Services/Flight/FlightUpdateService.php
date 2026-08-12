<?php

namespace App\Services\Flight;

use App\Exceptions\WorkflowConflictException;
use App\Models\Drone;
use App\Models\FlightSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class FlightUpdateService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function update(
        User $actor,
        FlightSession $flight,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): FlightSession {
        return DB::transaction(function () use ($actor, $flight, $data, $ipAddress, $userAgent, $requestId): FlightSession {
            $current = FlightSession::query()->lockForUpdate()->findOrFail($flight->flight_session_id);
            if ($current->flight_status !== 'planned') {
                throw new WorkflowConflictException(
                    'Only a planned flight can be updated.',
                    ['current_status' => $current->flight_status],
                );
            }

            if (array_key_exists('drone_id', $data)) {
                $drone = Drone::query()->where('organization_id', $actor->organization_id)
                    ->lockForUpdate()->find($data['drone_id']);
                if (! $drone instanceof Drone) {
                    throw (new ModelNotFoundException)->setModel(Drone::class, [$data['drone_id']]);
                }
                if ($drone->status !== 'available') {
                    throw new WorkflowConflictException(
                        'The selected drone is not available for flight planning.',
                        ['drone_id' => $drone->drone_id, 'current_status' => $drone->status],
                    );
                }
            }

            if (array_key_exists('pilot_user_id', $data)) {
                $pilot = User::query()->where('organization_id', $actor->organization_id)
                    ->lockForUpdate()->find($data['pilot_user_id']);
                if (! $pilot instanceof User) {
                    throw (new ModelNotFoundException)->setModel(User::class, [$data['pilot_user_id']]);
                }
                if (! $pilot->isActive()) {
                    throw new WorkflowConflictException(
                        'The selected pilot is not active.',
                        ['pilot_user_id' => $pilot->user_id],
                    );
                }
            }

            if (array_key_exists('flight_code', $data) && $data['flight_code'] !== $current->flight_code) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$data['flight_code']]);
                }
                if (FlightSession::query()->where('flight_code', $data['flight_code'])->exists()) {
                    throw new WorkflowConflictException(
                        'A flight with this code already exists.',
                        ['flight_code' => $data['flight_code']],
                    );
                }
            }

            $fields = ['drone_id', 'pilot_user_id', 'flight_code', 'planned_altitude_meters', 'notes'];
            $old = $current->only($fields);
            $current->fill($data)->save();
            $current->refresh();

            $this->auditLogger->record(
                action: 'flight.update', tableName: 'flight_sessions', recordId: $current->flight_session_id,
                userId: $actor->user_id, oldValues: $old, newValues: $current->only($fields),
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return FlightSession::query()->withLocationGeoJson()->findOrFail($current->flight_session_id);
        });
    }
}
