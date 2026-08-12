<?php

namespace App\Http\Controllers\Api\V1\Mission;

use App\Http\Controllers\Controller;
use App\Http\Resources\MissionTeamMemberResource;
use App\Http\Resources\SurveyMissionResource;
use App\Models\User;
use App\Services\Mission\ScopedMissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MissionShowController extends Controller
{
    // [MSN-03] Return one tenant-visible mission with team and flight summary.
    public function __invoke(Request $request, string $mission, ScopedMissionService $scoped): JsonResponse
    {
        /** @var User $actor */ $actor = $request->user();
        $model = $scoped->find($actor, $mission);
        $team = $model->teamMembers()->orderBy('team_role')->orderBy('user_id')->get();

        return response()->json(['data' => [
            'mission' => (new SurveyMissionResource($model))->resolve($request),
            'team' => MissionTeamMemberResource::collection($team)->resolve($request),
            'flight_summary' => $scoped->flightSummary($model),
        ], 'meta' => ['request_id' => $request->attributes->get('request_id')]]);
    }
}
