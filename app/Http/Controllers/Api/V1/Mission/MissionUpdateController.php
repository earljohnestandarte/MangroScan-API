<?php

namespace App\Http\Controllers\Api\V1\Mission;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mission\MissionUpdateRequest;
use App\Http\Resources\SurveyMissionResource;
use App\Models\User;
use App\Services\Mission\MissionUpdateService;
use App\Services\Mission\ScopedMissionService;
use App\Services\Site\ScopedSurveySiteService;
use Illuminate\Http\JsonResponse;

class MissionUpdateController extends Controller
{
    // [MSN-04] Update planning fields without bypassing lifecycle actions.
    public function __invoke(MissionUpdateRequest $request, string $mission, ScopedMissionService $scoped, ScopedSurveySiteService $sites, MissionUpdateService $service): JsonResponse
    {
        /** @var User $actor */ $actor = $request->user();
        $data = $request->validated();
        $model = $scoped->find($actor, $mission);
        $siteId = null;
        if (isset($data['site_id'])) {
            $siteId = $sites->find($actor, $data['site_id'])->site_id;
            unset($data['site_id']);
        }
        $model = $service->update($model, $actor, $data, $siteId, $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));

        return response()->json(['data' => (new SurveyMissionResource($model))->resolve($request), 'meta' => ['request_id' => $request->attributes->get('request_id')]]);
    }
}
