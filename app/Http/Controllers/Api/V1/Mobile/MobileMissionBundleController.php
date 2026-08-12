<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\FlightSessionResource;
use App\Http\Resources\MissionTeamMemberResource;
use App\Http\Resources\SiteBoundaryResource;
use App\Http\Resources\SurveyMissionResource;
use App\Http\Resources\SurveySiteResource;
use App\Models\User;
use App\Services\Mission\ScopedMissionService;
use App\Services\Mobile\MobileMissionBundleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileMissionBundleController extends Controller
{
    // [SYNC-03] Download one approved tenant mission for offline field work.
    public function __invoke(
        Request $request,
        string $mission,
        ScopedMissionService $scoped,
        MobileMissionBundleService $bundles,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $model = $scoped->find($actor, $mission);
        $model->setRelation('site', $model->site()->withCenterPointGeoJson()->firstOrFail());
        $bundle = $bundles->bundle($model);

        return response()->json([
            'data' => [
                'mission' => (new SurveyMissionResource($model))->resolve($request),
                'site' => (new SurveySiteResource($model->site))->resolve($request),
                'flights' => FlightSessionResource::collection($bundle['flights'])->resolve($request),
                'team' => MissionTeamMemberResource::collection($bundle['team'])->resolve($request),
                'boundaries' => SiteBoundaryResource::collection($bundle['boundaries'])->resolve($request),
                'plots' => [],
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
