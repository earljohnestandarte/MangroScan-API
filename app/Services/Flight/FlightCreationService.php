<?php

namespace App\Services\Flight;

use App\Exceptions\WorkflowConflictException;
use App\Models\Drone;
use App\Models\FlightSession;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class FlightCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(
        User $actor,
        SurveyMission $mission,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): FlightSession {
        return DB::transaction(function () use (
            $actor,
            $mission,
            $data,
            $ipAddress,
            $userAgent,
            $requestId,
        ): FlightSession {
            $currentMission = SurveyMission::query()
                ->lockForUpdate()
                ->findOrFail($mission->mission_id);

            if ($currentMission->mission_status !== 'planned' || $currentMission->approved_by === null) {
                throw new WorkflowConflictException(
                    'Flights can only be created for an approved, planned mission.',
                    [
                        'current_status' => $currentMission->mission_status,
                        'approved' => $currentMission->approved_by !== null,
                    ],
                );
            }

            $drone = Drone::query()
                ->where('organization_id', $actor->organization_id)
                ->lockForUpdate()
                ->find($data['drone_id']);

            if (! $drone instanceof Drone) {
                throw (new ModelNotFoundException)->setModel(Drone::class, [$data['drone_id']]);
            }

            if ($drone->status !== 'available') {
                throw new WorkflowConflictException(
                    'The selected drone is not available for flight planning.',
                    ['drone_id' => $drone->drone_id, 'current_status' => $drone->status],
                );
            }

            $pilot = User::query()
                ->where('organization_id', $actor->organization_id)
                ->lockForUpdate()
                ->find($data['pilot_user_id']);

            if (! $pilot instanceof User) {
                throw (new ModelNotFoundException)->setModel(User::class, [$data['pilot_user_id']]);
            }

            if (! $pilot->isActive()) {
                throw new WorkflowConflictException(
                    'The selected pilot is not active.',
                    ['pilot_user_id' => $pilot->user_id],
                );
            }

            $flight = FlightSession::query()->create([
                'mission_id' => $currentMission->mission_id,
                'drone_id' => $drone->drone_id,
                'pilot_user_id' => $pilot->user_id,
                'flight_code' => $data['flight_code'],
                'planned_altitude_meters' => $data['planned_altitude_meters'] ?? null,
                'notes' => $data['notes'] ?? null,
                'flight_status' => 'planned',
                'quality_status' => 'pending',
            ]);

            $this->auditLogger->record(
                action: 'flight.create',
                tableName: 'flight_sessions',
                recordId: $flight->flight_session_id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: $flight->only([
                    'flight_session_id',
                    'mission_id',
                    'drone_id',
                    'pilot_user_id',
                    'flight_code',
                    'planned_altitude_meters',
                    'flight_status',
                    'quality_status',
                    'notes',
                ]),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $flight->refresh();
        });
    }
}
