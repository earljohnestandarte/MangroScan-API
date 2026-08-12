<?php

namespace App\Services\Flight;

use App\Exceptions\WorkflowConflictException;
use App\Models\FlightSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FlightCompletionService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function complete(
        User $actor,
        FlightSession $flight,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): FlightSession {
        return DB::transaction(function () use (
            $actor,
            $flight,
            $data,
            $ipAddress,
            $userAgent,
            $requestId,
        ): FlightSession {
            $current = FlightSession::query()
                ->lockForUpdate()
                ->findOrFail($flight->flight_session_id);

            if ($current->flight_status !== 'flying' || $current->started_at === null) {
                throw new WorkflowConflictException(
                    'Only a started flight can be completed.',
                    [
                        'current_status' => $current->flight_status,
                        'started_at' => $current->started_at?->toIso8601String(),
                    ],
                );
            }

            $startedAt = CarbonImmutable::instance($current->started_at)->utc();
            $endedAt = CarbonImmutable::parse($data['ended_at'])->utc();

            if (! $endedAt->isAfter($startedAt)) {
                throw new WorkflowConflictException(
                    'Flight completion time must be after its start time.',
                    [
                        'started_at' => $startedAt->toIso8601String(),
                        'ended_at' => $endedAt->toIso8601String(),
                    ],
                );
            }

            $old = [
                'flight_status' => $current->flight_status,
                'ended_at' => $current->ended_at,
                'landing_location' => $this->landingGeoJson($current),
                'actual_avg_altitude_meters' => $current->actual_avg_altitude_meters,
                'flight_duration_minutes' => $current->flight_duration_minutes,
                'notes' => $current->notes,
            ];
            $durationMinutes = round($startedAt->diffInSeconds($endedAt) / 60, 2);
            $updates = [
                'flight_status' => 'completed',
                'ended_at' => $endedAt->toIso8601String(),
                'flight_duration_minutes' => $durationMinutes,
                'sync_version' => DB::raw('sync_version + 1'),
                'updated_at' => now(),
            ];

            foreach (['actual_avg_altitude_meters', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field];
                }
            }

            DB::table('flight_sessions')
                ->where('flight_session_id', $current->flight_session_id)
                ->update($updates);

            if (array_key_exists('landing_location', $data)) {
                $this->setLandingLocation($current, $data['landing_location']);
            }

            $newLandingLocation = array_key_exists('landing_location', $data)
                ? $data['landing_location']
                : $old['landing_location'];
            $newAltitude = array_key_exists('actual_avg_altitude_meters', $data)
                ? $data['actual_avg_altitude_meters']
                : $old['actual_avg_altitude_meters'];
            $newNotes = array_key_exists('notes', $data)
                ? $data['notes']
                : $old['notes'];

            $this->auditLogger->record(
                action: 'flight.complete',
                tableName: 'flight_sessions',
                recordId: $current->flight_session_id,
                userId: $actor->user_id,
                oldValues: $old,
                newValues: [
                    'flight_status' => 'completed',
                    'ended_at' => $endedAt->toIso8601String(),
                    'landing_location' => $newLandingLocation,
                    'actual_avg_altitude_meters' => $newAltitude,
                    'flight_duration_minutes' => $durationMinutes,
                    'notes' => $newNotes,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return FlightSession::query()
                ->withLocationGeoJson()
                ->findOrFail($current->flight_session_id);
        });
    }

    /** @param array<string, mixed>|null $location */
    private function setLandingLocation(FlightSession $flight, ?array $location): void
    {
        if ($location === null) {
            DB::table('flight_sessions')
                ->where('flight_session_id', $flight->flight_session_id)
                ->update(['landing_location' => null]);

            return;
        }

        $geoJson = json_encode($location, JSON_THROW_ON_ERROR);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'UPDATE flight_sessions SET landing_location = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) WHERE flight_session_id = ?',
                [$geoJson, $flight->flight_session_id],
            );

            return;
        }

        DB::table('flight_sessions')
            ->where('flight_session_id', $flight->flight_session_id)
            ->update(['landing_location' => $geoJson]);
    }

    /** @return array<string, mixed>|null */
    private function landingGeoJson(FlightSession $flight): ?array
    {
        if ($flight->landing_location === null) {
            return null;
        }

        $value = DB::getDriverName() === 'pgsql'
            ? DB::table('flight_sessions')
                ->where('flight_session_id', $flight->flight_session_id)
                ->selectRaw('ST_AsGeoJSON(landing_location)::json AS location')
                ->value('location')
            : $flight->landing_location;

        if (is_string($value)) {
            $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }

        return is_array($value) ? $value : null;
    }
}
