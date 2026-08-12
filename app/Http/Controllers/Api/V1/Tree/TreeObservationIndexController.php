<?php

namespace App\Http\Controllers\Api\V1\Tree;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tree\TreeObservationIndexRequest;
use App\Http\Resources\TreeObservationResource;
use App\Models\MangroveSpecies;
use App\Models\TreeObservation;
use App\Models\User;
use App\Services\Flight\ScopedFlightService;
use App\Services\Mission\ScopedMissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class TreeObservationIndexController extends Controller
{
    // [TREE-01] Filter tenant-visible canonical tree observations.
    public function __invoke(
        TreeObservationIndexRequest $request,
        ScopedMissionService $missions,
        ScopedFlightService $flights,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();

        if (! empty($validated['mission_id'])) {
            $missions->find($actor, $validated['mission_id']);
        }
        if (! empty($validated['flight_id'])) {
            $flights->find($actor, $validated['flight_id']);
        }
        if (! empty($validated['species_id'])) {
            MangroveSpecies::query()->findOrFail($validated['species_id']);
        }

        $query = TreeObservation::query()
            ->withGeometryGeoJson()
            ->whereHas('mission.site', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            });

        foreach (['mission_id', 'species_id'] as $filter) {
            if (! empty($validated[$filter])) {
                $column = $filter === 'species_id' ? 'final_species_id' : 'mission_id';
                $query->where($column, $validated[$filter]);
            }
        }
        if (! empty($validated['flight_id'])) {
            $query->where('flight_session_id', $validated['flight_id']);
        }
        if (! empty($validated['validation_status'])) {
            $query->where('validation_status', $validated['validation_status']);
        }
        if (isset($validated['min_confidence'])) {
            $query->where('detection_confidence', '>=', $validated['min_confidence']);
        }

        $observations = $query->orderByDesc('created_at')
            ->orderByDesc('tree_observation_id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => TreeObservationResource::collection(collect($observations->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $observations->currentPage(),
                'per_page' => $observations->perPage(),
                'total' => $observations->total(),
                'last_page' => $observations->lastPage(),
            ],
        ]);
    }
}
