<?php

namespace App\Http\Controllers\Api\V1\Mission;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mission\MissionApprovalRequest;
use App\Http\Resources\SurveyMissionResource;
use App\Models\User;
use App\Services\Mission\MissionApprovalService;
use App\Services\Mission\ScopedMissionService;
use Illuminate\Http\JsonResponse;

class MissionApprovalController extends Controller
{
    // [MSN-06] Record one final approval/rejection decision.
    public function __invoke(MissionApprovalRequest $request, string $mission, ScopedMissionService $scoped, MissionApprovalService $service): JsonResponse
    {/** @var User $actor */ $actor = $request->user();
        $data = $request->validated();
        $model = $service->decide($scoped->find($actor, $mission), $actor, $data['decision'], $data['notes'] ?? null, $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));

        return response()->json(['data' => (new SurveyMissionResource($model))->resolve($request), 'meta' => ['request_id' => $request->attributes->get('request_id')]]);
    }
}
