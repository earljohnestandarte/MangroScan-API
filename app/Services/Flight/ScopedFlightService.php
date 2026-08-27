<?php

namespace App\Services\Flight;

use App\Models\FlightSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScopedFlightService
{
    public function find(User $actor, string $id): FlightSession
    {
        return FlightSession::query()
            ->withLocationGeoJson()
            ->where('flight_sessions.flight_session_id', $id)
            ->whereHas('mission', function (Builder $missionQuery) use ($actor): void {
                $missionQuery->whereHas('site', function (Builder $siteQuery) use ($actor): void {
                    $siteQuery->where(
                        'survey_sites.organization_id',
                        $actor->organization_id
                    );
                });
            })
            ->firstOrFail();
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