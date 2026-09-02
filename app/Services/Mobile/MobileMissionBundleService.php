<?php

namespace App\Services\Mobile;

use App\Models\FlightSession;
use App\Models\MissionTeamMember;
use App\Models\SiteBoundary;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Auth\DroneOperatorScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class MobileMissionBundleService
{
    public function __construct(private readonly DroneOperatorScope $operatorScope) {}

    /**
     * @return array{
     *     flights: Collection<int, FlightSession>,
     *     team: Collection<int, MissionTeamMember>,
     *     boundaries: Collection<int, SiteBoundary>
     * }
     */
    public function bundle(SurveyMission $mission, User $actor): array
    {
        if ($mission->approved_by === null) {
            throw (new ModelNotFoundException)->setModel(SurveyMission::class, [$mission->mission_id]);
        }

        $flightQuery = FlightSession::query()
            ->withLocationGeoJson()
            ->where('mission_id', $mission->mission_id);

        return [
            'flights' => $this->operatorScope->flights($flightQuery, $actor)
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
