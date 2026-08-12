<?php

namespace App\Http\Controllers\Api\V1\Mission;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mission\MissionCompleteRequest;
use App\Http\Resources\SurveyMissionResource;
use App\Models\User;
use App\Services\Mission\MissionCompletionService;
use App\Services\Mission\ScopedMissionService;
use Illuminate\Http\JsonResponse;

class MissionCompleteController extends Controller
{
    // [MSN-08] Complete a started mission after every flight is complete.
    public function __invoke(
        MissionCompleteRequest $request,
        string $mission,
        ScopedMissionService $scoped,
        MissionCompletionService $completion,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $completed = $completion->complete(
            $actor, $scoped->find($actor, $mission), $request->validated(),
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new SurveyMissionResource($completed))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
