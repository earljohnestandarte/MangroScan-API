<?php

namespace App\Services\Flight;

use App\Exceptions\WorkflowConflictException;
use App\Models\FlightSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlightWaypointReplacementService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param list<array<string, mixed>> $waypoints */
    public function replace(User $actor, FlightSession $flight, array $waypoints, ?string $ip, ?string $agent, ?string $requestId): int
    {
        return DB::transaction(function () use ($actor, $flight, $waypoints, $ip, $agent, $requestId): int {
            $current = FlightSession::query()->lockForUpdate()->findOrFail($flight->flight_session_id);
            if ($current->flight_status !== 'planned') {
                throw new WorkflowConflictException('Waypoints can only be replaced while the flight is planned.', ['current_status' => $current->flight_status]);
            }
            $old = $this->snapshot($current->flight_session_id);
            DB::table('flight_waypoints')->where('flight_session_id', $current->flight_session_id)->delete();
            foreach ($waypoints as $waypoint) {
                $this->insert($current->flight_session_id, $waypoint);
            }
            $new = $this->snapshot($current->flight_session_id);
            $this->auditLogger->record('flight.waypoints.replace', 'flight_waypoints', $current->flight_session_id, $actor->user_id, ['waypoints' => $old], ['waypoints' => $new, 'count' => count($new)], $ip, $agent, $requestId);

            return count($new);
        });
    }

    /** @param array<string, mixed> $waypoint */
    private function insert(string $flightId, array $waypoint): void
    {
        $values = [(string) Str::uuid(), $flightId, $waypoint['sequence_no'], json_encode($waypoint['location'], JSON_THROW_ON_ERROR), $waypoint['altitude_meters'] ?? null, $waypoint['speed_mps'] ?? null, $waypoint['action'] ?? null, now()];
        if (DB::getDriverName() === 'pgsql') {
            DB::insert('INSERT INTO flight_waypoints (waypoint_id, flight_session_id, sequence_no, waypoint_location, altitude_meters, speed_mps, action, created_at) VALUES (?, ?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?, ?, ?, ?)', $values);
        } else {
            DB::table('flight_waypoints')->insert(array_combine(['waypoint_id', 'flight_session_id', 'sequence_no', 'waypoint_location', 'altitude_meters', 'speed_mps', 'action', 'created_at'], $values));
        }
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(string $flightId): array
    {
        $query = DB::table('flight_waypoints')->where('flight_session_id', $flightId)->orderBy('sequence_no')->orderBy('waypoint_id');
        if (DB::getDriverName() === 'pgsql') {
            $query->selectRaw('sequence_no, ST_AsGeoJSON(waypoint_location)::json AS location, altitude_meters, speed_mps, action');
        } else {
            $query->select(['sequence_no', 'waypoint_location as location', 'altitude_meters', 'speed_mps', 'action']);
        }

        return $query->get()->map(function (object $row): array {
            $location = is_string($row->location) ? json_decode($row->location, true, flags: JSON_THROW_ON_ERROR) : $row->location;

            return ['sequence_no' => (int) $row->sequence_no, 'location' => $location, 'altitude_meters' => $row->altitude_meters, 'speed_mps' => $row->speed_mps, 'action' => $row->action];
        })->all();
    }
}
