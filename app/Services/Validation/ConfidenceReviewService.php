<?php

namespace App\Services\Validation;

use App\Models\SurveyMission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConfidenceReviewService
{
    /** @param array<string, mixed> $filters */
    public function list(SurveyMission $mission, array $filters): array
    {
        $types = isset($filters['result_type'])
            ? [$filters['result_type']]
            : ['detection', 'species', 'height', 'age'];
        $rows = collect();
        foreach ($types as $type) {
            $rows = $rows->concat($this->rowsForType($mission->mission_id, $type, $filters['flight_id'] ?? null));
        }

        $rows = $rows->map(function (object $row): array {
            $score = (float) $row->confidence_score;
            $severity = $row->flag_severity ?: $this->severity($score);

            return [
                'result_id' => $row->result_id,
                'result_type' => $row->result_type,
                'tree_observation_id' => $row->tree_observation_id,
                'mission_id' => $row->mission_id,
                'flight_session_id' => $row->flight_session_id,
                'confidence_score' => number_format($score, 4, '.', ''),
                'location' => $this->geometry($row->tree_location),
                'status' => $row->flag_status ?: 'open',
                'severity' => $severity,
                'review_note' => $row->review_note,
                'assigned_to' => $row->assigned_to,
                'reason' => $row->reason,
                'resolution_notes' => $row->resolution_notes,
                'flagged_at' => $row->flagged_at,
            ];
        });

        if (isset($filters['status'])) {
            $rows = $rows->where('status', $filters['status']);
        }
        if (isset($filters['severity'])) {
            $rows = $rows->where('severity', $filters['severity']);
        }

        $rows = $rows->sortBy([
            fn (array $left, array $right): int => $this->severityRank($right['severity']) <=> $this->severityRank($left['severity']),
            fn (array $left, array $right): int => ((float) $left['confidence_score']) <=> ((float) $right['confidence_score']),
            fn (array $left, array $right): int => $left['result_id'] <=> $right['result_id'],
        ])->values();

        $summary = [
            'total' => $rows->count(),
            'open' => $rows->where('status', 'open')->count(),
            'in_review' => $rows->where('status', 'in_review')->count(),
            'resolved' => $rows->where('status', 'resolved')->count(),
            'dismissed' => $rows->where('status', 'dismissed')->count(),
        ];
        $groups = collect(['critical', 'high', 'medium', 'low'])->mapWithKeys(
            fn (string $severity): array => [$severity => $rows->where('severity', $severity)->count()],
        )->all();
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);

        return [
            'data' => $rows->forPage($page, $perPage)->values()->all(),
            'summary' => $summary,
            'groups' => $groups,
            'map' => $rows->map(fn (array $row): array => [
                'result_id' => $row['result_id'],
                'tree_observation_id' => $row['tree_observation_id'],
                'severity' => $row['severity'],
                'location' => $row['location'],
            ])->values()->all(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $rows->count(),
                'last_page' => max(1, (int) ceil($rows->count() / $perPage)),
            ],
        ];
    }

    private function rowsForType(string $missionId, string $type, ?string $flightId): Collection
    {
        [$table, $id, $score] = match ($type) {
            'detection' => ['tree_observations', 'tree_observation_id', 'detection_confidence'],
            'species' => ['species_classification_results', 'classification_result_id', 'confidence_score'],
            'height' => ['canopy_height_estimations', 'height_estimation_id', 'height_confidence_score'],
            'age' => ['age_estimations', 'age_estimation_id', 'confidence_score'],
        };
        $query = DB::table($table.' as result');
        if ($type === 'detection') {
            $query->where('result.mission_id', $missionId)
                ->whereNull('result.deleted_at');
            $treeId = 'result.tree_observation_id';
            $missionColumn = 'result.mission_id';
            $flightColumn = 'result.flight_session_id';
            $locationColumn = 'result.tree_location';
        } else {
            $query->join('tree_observations as tree', 'tree.tree_observation_id', '=', 'result.tree_observation_id')
                ->where('tree.mission_id', $missionId)
                ->whereNull('tree.deleted_at');
            $treeId = 'tree.tree_observation_id';
            $missionColumn = 'tree.mission_id';
            $flightColumn = 'tree.flight_session_id';
            $locationColumn = 'tree.tree_location';
        }
        $query->leftJoin('confidence_flags as flag', function ($join) use ($type, $id): void {
            $join->on('flag.result_id', '=', 'result.'.$id)->where('flag.result_type', '=', $type);
        })->whereNotNull('result.'.$score)->where('result.'.$score, '<', 0.8);
        if ($flightId !== null) {
            $query->where($flightColumn, $flightId);
        }

        $query->selectRaw('result.'.$id.' as result_id')
            ->selectRaw('? as result_type', [$type])
            ->selectRaw($treeId.' as tree_observation_id')
            ->selectRaw($missionColumn.' as mission_id')
            ->selectRaw($flightColumn.' as flight_session_id')
            ->selectRaw('result.'.$score.' as confidence_score')
            ->addSelect(['flag.status as flag_status', 'flag.severity as flag_severity', 'flag.review_note', 'flag.assigned_to', 'flag.reason', 'flag.resolution_notes', 'flag.created_at as flagged_at']);
        if (DB::getDriverName() === 'pgsql') {
            $query->selectRaw('ST_AsGeoJSON('.$locationColumn.')::json as tree_location');
        } else {
            $query->selectRaw($locationColumn.' as tree_location');
        }

        return $query->get();
    }

    public function severity(float $score): string
    {
        return match (true) {
            $score < 0.4 => 'critical',
            $score < 0.6 => 'high',
            $score < 0.75 => 'medium',
            default => 'low',
        };
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 4, 'high' => 3, 'medium' => 2, default => 1,
        };
    }

    private function geometry(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
