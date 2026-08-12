<?php

namespace App\Http\Controllers\Api\V1\Tree;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tree\MissionTreeCountRequest;
use App\Http\Resources\TreeCountSummaryResource;
use App\Models\MangroveSpecies;
use App\Models\TreeObservation;
use App\Models\User;
use App\Services\Mission\ScopedMissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class MissionTreeCountController extends Controller
{
    // [COUNT-01] Derive current overall and per-species canonical tree counts.
    public function __invoke(
        MissionTreeCountRequest $request,
        string $mission,
        ScopedMissionService $missions,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $missionRecord = $missions->find($actor, $mission);
        $speciesId = $request->validated('species_id');
        if ($speciesId) {
            MangroveSpecies::query()->findOrFail($speciesId);
        }

        $base = TreeObservation::query()->where('mission_id', $missionRecord->mission_id);
        if ($speciesId) {
            $base->where('final_species_id', $speciesId);
        }

        $groups = (clone $base)->whereNotNull('final_species_id')
            ->selectRaw('final_species_id AS species_id, COUNT(*) AS total_detected_trees')
            ->selectRaw("SUM(CASE WHEN validation_status IN ('validated', 'corrected') THEN 1 ELSE 0 END) AS validated_tree_count")
            ->groupBy('final_species_id')->orderBy('final_species_id')->get();

        $rows = $speciesId
            ? $this->speciesRows($groups, $missionRecord->mission_id, $missionRecord->site_id)
            : collect([$this->summaryRow(
                missionId: $missionRecord->mission_id,
                siteId: $missionRecord->site_id,
                speciesId: null,
                total: (clone $base)->count(),
                validated: (clone $base)->whereIn('validation_status', ['validated', 'corrected'])->count(),
            )])->concat($this->speciesRows($groups, $missionRecord->mission_id, $missionRecord->site_id));

        return response()->json([
            'data' => TreeCountSummaryResource::collection($rows)->resolve($request),
        ]);
    }

    /** @param Collection<int, object> $groups */
    private function speciesRows(Collection $groups, string $missionId, string $siteId): Collection
    {
        return $groups->map(fn (object $group): array => $this->summaryRow(
            missionId: $missionId,
            siteId: $siteId,
            speciesId: $group->species_id,
            total: (int) $group->total_detected_trees,
            validated: (int) $group->validated_tree_count,
        ));
    }

    /** @return array<string, mixed> */
    private function summaryRow(string $missionId, string $siteId, ?string $speciesId, int $total, int $validated): array
    {
        return [
            'tree_count_summary_id' => null,
            'mission_id' => $missionId,
            'site_id' => $siteId,
            'species_id' => $speciesId,
            'model_run_id' => null,
            'total_detected_trees' => $total,
            'validated_tree_count' => $validated,
            'estimated_density_per_hectare' => null,
            'count_confidence_score' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }
}
