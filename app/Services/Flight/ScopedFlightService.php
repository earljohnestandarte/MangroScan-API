<?php

namespace App\Services\Flight;

use App\Models\FlightSession;
use App\Models\User;
use App\Services\Auth\DroneOperatorScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScopedFlightService
{
    public function __construct(private readonly DroneOperatorScope $operatorScope) {}

    public function find(User $actor, string $id): FlightSession
    {
        // First find the flight by its actual primary key.
        $flight = FlightSession::query()
            ->find($id);

        if (! $flight instanceof FlightSession) {
            throw (new ModelNotFoundException)
                ->setModel(FlightSession::class, [$id]);
        }

        // Load the mission and site used for organization scoping.
        $flight->load('mission.site');

        $organizationId = $flight->mission?->site?->organization_id;

        // Do not allow access to a flight belonging to another organization.
        if ($organizationId !== $actor->organization_id) {
            throw (new ModelNotFoundException)
                ->setModel(FlightSession::class, [$id]);
        }

        return $flight;
    }

    /** @return array{waypoint_count: int, media_count: int} */
    public function childCounts(FlightSession $flight): array
    {
        return [
            'waypoint_count' => $this->countChildren(
                'flight_waypoints',
                $flight->flight_session_id
            ),
            'media_count' => $this->countChildren(
                'media_assets',
                $flight->flight_session_id,
                softDeletes: true
            ),
        ];
    }

    private function countChildren(
        string $table,
        string $flightId,
        bool $softDeletes = false
    ): int {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table)
            ->where('flight_session_id', $flightId);

        if ($softDeletes && Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->count();
    }
}