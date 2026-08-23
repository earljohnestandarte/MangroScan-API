<?php

namespace App\Http\Controllers\Api\V1\Tree;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tree\MissionLayerBuildRequest;
use App\Models\User;
use App\Services\Mission\ScopedMissionService;
use App\Services\Tree\MissionLayerBuildService;
use Illuminate\Http\JsonResponse;

class MissionLayerBuildController extends Controller
{
    public function __invoke(
        MissionLayerBuildRequest $request,
        string $mission,
        ScopedMissionService $missions,
        MissionLayerBuildService $layers,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $job = $layers->queue(
            $missions->find($actor, $mission), $actor, $request->validated(),
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json(['data' => ['job_id' => $job->processing_job_id]], 202);
    }
}
