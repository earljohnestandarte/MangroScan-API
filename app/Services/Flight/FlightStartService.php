<?php

namespace App\Services\Flight;

use App\Exceptions\WorkflowConflictException;
use App\Models\FlightChecklist;
use App\Models\FlightSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FlightStartService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function start(
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

            if ($current->flight_status !== 'planned') {
                throw new WorkflowConflictException(
                    'Only a planned flight can be started.',
                    ['current_status' => $current->flight_status],
                );
            }

            $latestPreflight = FlightChecklist::query()
                ->where('flight_session_id', $current->flight_session_id)
                ->where('checklist_type', 'pre_flight')
                ->orderByDesc('created_at')
                ->orderByDesc('checklist_id')
                ->lockForUpdate()
                ->first();

            if (! $latestPreflight instanceof FlightChecklist || $latestPreflight->overall_status !== 'passed') {
                throw new WorkflowConflictException(
                    'A latest passed pre-flight checklist is required before takeoff.',
                    [
                        'latest_preflight_status' => $latestPreflight?->overall_status,
                        'latest_preflight_id' => $latestPreflight?->checklist_id,
                    ],
                );
            }

            $old = [
                'flight_status' => $current->flight_status,
                'started_at' => $current->started_at,
                'takeoff_location' => $this->locationGeoJson($current, 'takeoff_location'),
            ];

            $startedAt = CarbonImmutable::parse($data['started_at'])->utc();

            // Preserve the submitted instant across database session timezones.
            // Eloquent's date serialization omits the offset before PostgreSQL
            // interprets a timestamptz value, so pass canonical ISO-8601 here.
            DB::table('flight_sessions')
                ->where('flight_session_id', $current->flight_session_id)
                ->update([
                    'flight_status' => 'flying',
                    'started_at' => $startedAt->toIso8601String(),
                    'sync_version' => DB::raw('sync_version + 1'),
                    'updated_at' => now(),
                ]);

            if (array_key_exists('takeoff_location', $data) && $data['takeoff_location'] !== null) {
                $this->setLocation($current, 'takeoff_location', $data['takeoff_location']);
            }

            $this->auditLogger->record(
                action: 'flight.start',
                tableName: 'flight_sessions',
                recordId: $current->flight_session_id,
                userId: $actor->user_id,
                oldValues: $old,
                newValues: [
                    'flight_status' => 'flying',
                    'started_at' => $startedAt->toIso8601String(),
                    'takeoff_location' => $data['takeoff_location'] ?? null,
                    'preflight_checklist_id' => $latestPreflight->checklist_id,
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

    /** @param array<string, mixed> $location */
    private function setLocation(FlightSession $flight, string $column, array $location): void
    {
        $geoJson = json_encode($location, JSON_THROW_ON_ERROR);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "UPDATE flight_sessions SET {$column} = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) WHERE flight_session_id = ?",
                [$geoJson, $flight->flight_session_id],
            );

            return;
        }

        DB::table('flight_sessions')
            ->where('flight_session_id', $flight->flight_session_id)
            ->update([$column => $geoJson]);
    }

    /** @return array<string, mixed>|null */
    private function locationGeoJson(FlightSession $flight, string $column): ?array
    {
        if ($flight->getAttribute($column) === null) {
            return null;
        }

        if (DB::getDriverName() === 'pgsql') {
            $value = DB::table('flight_sessions')
                ->where('flight_session_id', $flight->flight_session_id)
                ->selectRaw("ST_AsGeoJSON({$column})::json AS location")
                ->value('location');
        } else {
            $value = $flight->getAttribute($column);
        }

        if (is_string($value)) {
            $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }

        return is_array($value) ? $value : null;
    }
}
