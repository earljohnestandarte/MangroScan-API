<?php

namespace App\Services\Dashboard;

use App\Exceptions\DownstreamServiceException;
use App\Http\Resources\GeospatialLayerResource;
use App\Models\GeospatialLayer;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Mission\ScopedMissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MissionDashboardService
{
    public function __construct(private readonly ScopedMissionService $missions) {}

    /** @return array<string, mixed> */
    public function get(User $actor, string $id, Request $request): array
    {
        $mission = $this->missions->find($actor, $id);
        $snapshot = DB::table('mv_dashboard_mission_metrics')
            ->where('organization_id', $actor->organization_id)
            ->where('mission_id', $mission->mission_id)
            ->first();
        if ($snapshot === null) {
            throw new DownstreamServiceException(
                'The dashboard snapshot has not been refreshed for this mission.',
                503,
                'SERVICE_UNAVAILABLE',
            );
        }

        return [
            'counts' => $this->counts($snapshot),
            'species' => $this->species($mission),
            'height' => $this->measurement($mission, 'final_height_meters', 'm'),
            'age' => $this->measurement($mission, 'final_estimated_age_years', 'years'),
            'accuracy' => $this->accuracy($mission),
            'layers' => GeospatialLayerResource::collection(
                GeospatialLayer::query()->where('mission_id', $mission->mission_id)
                    ->orderBy('layer_type')->orderBy('layer_name')->orderBy('layer_id')->get(),
            )->resolve($request),
        ];
    }

    /** @return array<string, int> */
    private function counts(object $row): array
    {
        return [
            'trees' => (int) $row->tree_count,
            'validated_trees' => (int) $row->validated_tree_count,
            'unvalidated_trees' => (int) $row->unvalidated_tree_count,
            'rejected_trees' => (int) $row->rejected_tree_count,
            'validation_sessions' => (int) $row->validation_session_count,
            'ground_truth_records' => (int) $row->ground_truth_count,
            'processing_jobs' => (int) $row->processing_job_count,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function species(SurveyMission $mission): array
    {
        $rows = DB::table('tree_observations as tree')
            ->leftJoin('mangrove_species as species', 'species.species_id', '=', 'tree.final_species_id')
            ->where('tree.mission_id', $mission->mission_id)
            ->whereNull('tree.deleted_at')
            ->select([
                'tree.final_species_id as species_id', 'species.scientific_name', 'species.common_name',
            ])->selectRaw('COUNT(*) AS tree_count')
            ->groupBy('tree.final_species_id', 'species.scientific_name', 'species.common_name')
            ->orderByDesc('tree_count')->orderBy('species.scientific_name')->orderBy('tree.final_species_id')
            ->get();
        $total = (int) $rows->sum(fn ($row): int => (int) $row->tree_count);

        return $rows->map(fn ($row): array => [
            'species_id' => $row->species_id,
            'scientific_name' => $row->scientific_name,
            'common_name' => $row->common_name,
            'tree_count' => (int) $row->tree_count,
            'percentage' => $total === 0 ? '0.00' : number_format((int) $row->tree_count * 100 / $total, 2, '.', ''),
        ])->all();
    }

    /** @return array<string, int|string|null> */
    private function measurement(SurveyMission $mission, string $column, string $unit): array
    {
        $summary = DB::table('tree_observations')
            ->where('mission_id', $mission->mission_id)
            ->whereNull('deleted_at')->whereNotNull($column)
            ->selectRaw("COUNT(*) AS sample_size, MIN($column) AS minimum, MAX($column) AS maximum, AVG($column) AS average")
            ->first();

        return [
            'sample_size' => (int) ($summary->sample_size ?? 0),
            'minimum' => $this->decimal($summary->minimum ?? null),
            'maximum' => $this->decimal($summary->maximum ?? null),
            'average' => $this->decimal($summary->average ?? null),
            'unit' => $unit,
        ];
    }

    /** @return array<string, string|null> */
    private function accuracy(SurveyMission $mission): array
    {
        $values = DB::table('v_mission_accuracy_summary')
            ->where('mission_id', $mission->mission_id)
            ->pluck('metric_value', 'metric_type');
        $result = [];
        foreach (['species_accuracy', 'count_precision', 'count_recall', 'count_f1', 'height_rmse', 'age_mae'] as $type) {
            $result[$type] = isset($values[$type]) ? number_format((float) $values[$type], 6, '.', '') : null;
        }

        return $result;
    }

    private function decimal(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }
}
