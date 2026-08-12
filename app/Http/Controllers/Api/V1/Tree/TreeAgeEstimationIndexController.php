<?php

namespace App\Http\Controllers\Api\V1\Tree;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgeEstimationResource;
use App\Models\TreeObservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TreeAgeEstimationIndexController extends Controller
{
    // [RESULT-03] Return one tenant-visible tree's age estimation history.
    public function __invoke(Request $request, string $tree): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $observation = TreeObservation::query()->whereHas('mission.site', function (Builder $query) use ($actor): void {
            $query->where('organization_id', $actor->organization_id);
        })->findOrFail($tree);
        $estimations = $observation->ageEstimations()
            ->orderByDesc('is_final')->orderByDesc('created_at')->orderBy('age_estimation_id')->get();

        return response()->json([
            'data' => AgeEstimationResource::collection($estimations)->resolve($request),
        ]);
    }
}
