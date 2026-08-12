<?php

namespace App\Services\Mobile;

use App\Models\FlightSession;
use App\Models\MissionTeamMember;
use App\Models\SiteBoundary;
use App\Models\SurveyMission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class MobileMissionBundleService
{
    /**
     * @return array{
     *     flights: Collection<int, FlightSession>,
     *     team: Collection<int, MissionTeamMember>,
     *     boundaries: Collection<int, SiteBoundary>
     * }
     */
    public function bundle(SurveyMission $mission): array
    {
        if ($mission->approved_by === null) {
            throw (new ModelNotFoundException)->setModel(SurveyMission::class, [$mission->mission_id]);
        }

        return [
            'flights' => FlightSession::query()
                ->withLocationGeoJson()
                ->where('mission_id', $mission->mission_id)
                ->orderBy('flight_code')
                ->orderBy('flight_session_id')
                ->get(),
            'team' => $mission->teamMembers()
                ->orderBy('team_role')
                ->orderBy('user_id')
                ->get(),
            'boundaries' => SiteBoundary::query()
                ->withBoundaryGeoJson()
                ->where('site_id', $mission->site_id)
                ->orderBy('boundary_name')
                ->orderBy('boundary_id')
                ->get(),
        ];
    }
}
