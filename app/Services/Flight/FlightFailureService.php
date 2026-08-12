<?php

namespace App\Services\Flight;

use App\Exceptions\WorkflowConflictException;
use App\Models\FlightSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FlightFailureService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function fail(User $actor, FlightSession $flight, array $data, ?string $ipAddress, ?string $userAgent, ?string $requestId): FlightSession
    {
        return DB::transaction(function () use ($actor, $flight, $data, $ipAddress, $userAgent, $requestId): FlightSession {
            $current = FlightSession::query()->lockForUpdate()->findOrFail($flight->flight_session_id);
            if ($current->flight_status !== 'flying' || $current->started_at === null) {
                throw new WorkflowConflictException('Only a started flight can be aborted or failed.', [
                    'current_status' => $current->flight_status,
                    'started_at' => $current->started_at?->utc()->toIso8601String(),
                ]);
            }

            $startedAt = CarbonImmutable::instance($current->started_at)->utc();
            $endedAt = array_key_exists('ended_at', $data) && $data['ended_at'] !== null
                ? CarbonImmutable::parse($data['ended_at'])->utc()
                : CarbonImmutable::now('UTC');
            if (! $endedAt->isAfter($startedAt)) {
                throw new WorkflowConflictException('Flight end time must be after its start time.', [
                    'started_at' => $startedAt->toIso8601String(), 'ended_at' => $endedAt->toIso8601String(),
                ]);
            }

            $old = [
                'flight_status' => $current->flight_status,
                'ended_at' => $current->ended_at?->utc()->toIso8601String(),
                'flight_duration_minutes' => $current->flight_duration_minutes,
                'notes' => $current->notes,
            ];
            $duration = round($startedAt->diffInSeconds($endedAt) / 60, 2);
            DB::table('flight_sessions')->where('flight_session_id', $current->flight_session_id)->update([
                'flight_status' => $data['status'], 'ended_at' => $endedAt->toIso8601String(),
                'flight_duration_minutes' => $duration, 'notes' => $data['reason'],
                'sync_version' => DB::raw('sync_version + 1'), 'updated_at' => now(),
            ]);

            $this->auditLogger->record(
                action: 'flight.fail', tableName: 'flight_sessions', recordId: $current->flight_session_id,
                userId: $actor->user_id, oldValues: $old,
                newValues: [
                    'flight_status' => $data['status'], 'ended_at' => $endedAt->toIso8601String(),
                    'flight_duration_minutes' => $duration, 'reason' => $data['reason'],
                ],
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return FlightSession::query()->withLocationGeoJson()->findOrFail($current->flight_session_id);
        });
    }
}
