<?php

namespace App\Http\Controllers\Api\V1\Tree;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgeEstimationResource;
use App\Http\Resources\CanopyHeightEstimationResource;
use App\Http\Resources\MediaAssetResource;
use App\Http\Resources\ModelRunResource;
use App\Http\Resources\SpeciesClassificationResultResource;
use App\Http\Resources\TreeObservationResource;
use App\Models\MediaAsset;
use App\Models\ModelRun;
use App\Models\TreeObservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TreeObservationShowController extends Controller
{
    // [TREE-02] Show one tenant-visible tree and its complete result provenance.
    public function __invoke(Request $request, string $tree): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $observation = TreeObservation::query()
            ->withGeometryGeoJson()
            ->whereHas('mission.site', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            })
            ->findOrFail($tree);

        $predictions = $observation->speciesPredictions()
            ->orderByDesc('is_final')->orderBy('rank_no')->orderByDesc('created_at')
            ->orderBy('classification_result_id')->get();
        $heights = $observation->heightEstimations()
            ->orderByDesc('is_final')->orderByDesc('created_at')
            ->orderBy('height_estimation_id')->get();
        $ages = $observation->ageEstimations()
            ->orderByDesc('is_final')->orderByDesc('created_at')
            ->orderBy('age_estimation_id')->get();
        $sourceMedia = $observation->source_media_id
            ? MediaAsset::query()->withCaptureLocationGeoJson()
                ->whereHas('flight.mission.site', function (Builder $query) use ($actor): void {
                    $query->where('organization_id', $actor->organization_id);
                })->find($observation->source_media_id)
            : null;
        $modelRun = $observation->model_run_id
            ? ModelRun::query()
                ->whereHas('processingJob.mission.site', function (Builder $query) use ($actor): void {
                    $query->where('organization_id', $actor->organization_id);
                })->find($observation->model_run_id)
            : null;

        return response()->json([
            'data' => [
                'tree' => (new TreeObservationResource($observation))->resolve($request),
                'species_predictions' => SpeciesClassificationResultResource::collection($predictions)->resolve($request),
                'height_estimations' => CanopyHeightEstimationResource::collection($heights)->resolve($request),
                'age_estimations' => AgeEstimationResource::collection($ages)->resolve($request),
                'source_media' => $sourceMedia ? (new MediaAssetResource($sourceMedia))->resolve($request) : null,
                'model_run' => $modelRun ? (new ModelRunResource($modelRun))->resolve($request) : null,
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
