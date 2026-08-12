<?php

namespace App\Http\Controllers\Api\V1\Tree;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tree\MissionTreeGeoJsonRequest;
use App\Models\MangroveSpecies;
use App\Models\TreeObservation;
use App\Models\User;
use App\Services\Mission\ScopedMissionService;
use Illuminate\Http\JsonResponse;

class MissionTreeGeoJsonController extends Controller
{
    // [TREE-03] Return one mission's tenant-scoped canonical trees as GeoJSON.
    public function __invoke(
        MissionTreeGeoJsonRequest $request,
        string $mission,
        ScopedMissionService $missions,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $missionRecord = $missions->find($actor, $mission);
        $validated = $request->validated();

        if (! empty($validated['species_id'])) {
            MangroveSpecies::query()->findOrFail($validated['species_id']);
        }

        $query = TreeObservation::query()
            ->withGeometryGeoJson()
            ->where('mission_id', $missionRecord->mission_id);
        if (! empty($validated['species_id'])) {
            $query->where('final_species_id', $validated['species_id']);
        }
        if ($request->boolean('validated_only')) {
            $query->whereIn('validation_status', ['validated', 'corrected']);
        }

        $features = $query->orderBy('tree_code')->orderBy('tree_observation_id')->get()
            ->map(fn (TreeObservation $tree): array => [
                'type' => 'Feature',
                'id' => $tree->tree_observation_id,
                'geometry' => $this->geometry($tree->tree_location_geojson ?? $tree->tree_location),
                'properties' => [
                    'tree_observation_id' => $tree->tree_observation_id,
                    'tree_entity_id' => $tree->tree_entity_id,
                    'tree_code' => $tree->tree_code,
                    'flight_session_id' => $tree->flight_session_id,
                    'detection_confidence' => $tree->detection_confidence,
                    'final_species_id' => $tree->final_species_id,
                    'final_height_meters' => $tree->final_height_meters,
                    'final_estimated_age_years' => $tree->final_estimated_age_years,
                    'validation_status' => $tree->validation_status,
                ],
            ])->all();

        $response = response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
        $response->headers->set('Content-Type', 'application/geo+json');

        return $response;
    }

    /** @return array<string, mixed> */
    private function geometry(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }

        return is_array($value) ? $value : [];
    }
}
