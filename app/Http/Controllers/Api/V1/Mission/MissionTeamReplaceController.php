<?php

namespace App\Http\Controllers\Api\V1\Mission;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mission\MissionTeamReplaceRequest;
use App\Http\Resources\MissionTeamMemberResource;
use App\Models\User;
use App\Services\Mission\MissionTeamReplacementService;
use App\Services\Mission\ScopedMissionService;
use Illuminate\Http\JsonResponse;

class MissionTeamReplaceController extends Controller
{
    // [TEAM-01] Replace the complete pre-approval mission team atomically.
    public function __invoke(MissionTeamReplaceRequest $request, string $mission, ScopedMissionService $scoped, MissionTeamReplacementService $service): JsonResponse
    {/** @var User $actor */ $actor = $request->user();
        $model = $scoped->find($actor, $mission);
        $team = $service->replace($model, $actor, $request->validated('members'), $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));

        return response()->json(['data' => MissionTeamMemberResource::collection($team)->resolve($request), 'meta' => ['request_id' => $request->attributes->get('request_id')]]);
    }
}
