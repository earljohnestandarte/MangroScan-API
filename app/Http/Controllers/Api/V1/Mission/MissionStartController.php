<?php

namespace App\Http\Controllers\Api\V1\Mission;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mission\MissionStartRequest;
use App\Http\Resources\SurveyMissionResource;
use App\Models\User;
use App\Services\Mission\MissionStartService;
use App\Services\Mission\ScopedMissionService;
use Illuminate\Http\JsonResponse;

class MissionStartController extends Controller
{
    // [MSN-07] Start an approved tenant mission.
    public function __invoke(
        MissionStartRequest $request,
        string $mission,
        ScopedMissionService $scoped,
        MissionStartService $start,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $started = $start->start(
            $actor, $scoped->find($actor, $mission), $request->validated(),
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new SurveyMissionResource($started))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
