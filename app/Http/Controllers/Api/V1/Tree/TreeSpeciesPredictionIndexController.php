<?php

namespace App\Http\Controllers\Api\V1\Tree;

use App\Http\Controllers\Controller;
use App\Http\Resources\SpeciesClassificationResultResource;
use App\Models\TreeObservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TreeSpeciesPredictionIndexController extends Controller
{
    // [RESULT-01] Return one tenant-visible tree's species prediction history.
    public function __invoke(Request $request, string $tree): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $observation = TreeObservation::query()
            ->whereHas('mission.site', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            })->findOrFail($tree);
        $predictions = $observation->speciesPredictions()
            ->orderByDesc('is_final')->orderBy('rank_no')->orderByDesc('created_at')
            ->orderBy('classification_result_id')->get();

        return response()->json([
            'data' => SpeciesClassificationResultResource::collection($predictions)->resolve($request),
        ]);
    }
}
