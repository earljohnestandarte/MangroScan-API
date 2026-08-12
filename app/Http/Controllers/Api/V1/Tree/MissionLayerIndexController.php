<?php

namespace App\Http\Controllers\Api\V1\Tree;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tree\MissionLayerIndexRequest;
use App\Http\Resources\GeospatialLayerResource;
use App\Models\GeospatialLayer;
use App\Models\User;
use App\Services\Mission\ScopedMissionService;
use Illuminate\Http\JsonResponse;

class MissionLayerIndexController extends Controller
{
    // [LAYER-01] List safe geospatial output metadata for one tenant mission.
    public function __invoke(MissionLayerIndexRequest $request, string $mission, ScopedMissionService $missions): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $missionRecord = $missions->find($actor, $mission);
        $query = GeospatialLayer::query()->where('mission_id', $missionRecord->mission_id);
        if ($request->validated('type')) {
            $query->where('layer_type', $request->validated('type'));
        }

        $layers = $query->orderBy('layer_type')->orderBy('layer_name')->orderBy('layer_id')->get();

        return response()->json(['data' => GeospatialLayerResource::collection($layers)->resolve($request)]);
    }
}
