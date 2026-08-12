<?php

namespace App\Http\Controllers\Api\V1\Mission;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mission\MissionStoreRequest;
use App\Http\Resources\SurveyMissionResource;
use App\Models\User;
use App\Services\Mission\MissionCreationService;
use App\Services\Site\ScopedSurveySiteService;
use Illuminate\Http\JsonResponse;

class MissionStoreController extends Controller
{
    // [MSN-02] Create one planned mission inside a tenant-visible site.
    public function __invoke(MissionStoreRequest $request, ScopedSurveySiteService $scopedSites, MissionCreationService $creation): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();
        $site = $scopedSites->find($actor, $data['site_id']);
        $mission = $creation->create($actor, $site, $data, $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));

        return response()->json([
            'data' => (new SurveyMissionResource($mission))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
