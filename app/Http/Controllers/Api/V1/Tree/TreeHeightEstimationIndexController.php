<?php

namespace App\Http\Controllers\Api\V1\Tree;

use App\Http\Controllers\Controller;
use App\Http\Resources\CanopyHeightEstimationResource;
use App\Models\TreeObservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TreeHeightEstimationIndexController extends Controller
{
    // [RESULT-02] Return one tenant-visible tree's height estimation history.
    public function __invoke(Request $request, string $tree): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $observation = TreeObservation::query()->whereHas('mission.site', function (Builder $query) use ($actor): void {
            $query->where('organization_id', $actor->organization_id);
        })->findOrFail($tree);
        $estimations = $observation->heightEstimations()
            ->orderByDesc('is_final')->orderByDesc('created_at')->orderBy('height_estimation_id')->get();

        return response()->json([
            'data' => CanopyHeightEstimationResource::collection($estimations)->resolve($request),
        ]);
    }
}
