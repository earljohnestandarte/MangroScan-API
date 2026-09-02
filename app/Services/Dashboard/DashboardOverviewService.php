<?php

namespace App\Services\Dashboard;

use App\Models\SurveyMission;
use App\Models\SurveySite;
use App\Models\User;
use App\Services\Auth\DroneOperatorScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class DashboardOverviewService
{
    public function __construct(private readonly DroneOperatorScope $operatorScope) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, array<string, int|array<string, int>>>
     */
    public function get(User $actor, array $filters): array
    {
        $siteId = $this->siteId($actor, $filters['site_id'] ?? null);
        $missionId = $this->missionId($actor, $filters['mission_id'] ?? null, $siteId);
        $allowedMissionIds = $this->allowedMissionIds($actor);

        $query = DB::table('mv_dashboard_mission_metrics')
            ->where('organization_id', $actor->organization_id);
        if ($allowedMissionIds !== null) {
            $query->whereIn('mission_id', $allowedMissionIds);
        }
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }
        if ($missionId !== null) {
            $query->where('mission_id', $missionId);
        }
        if (isset($filters['from'])) {
            $query->whereDate(DB::raw('COALESCE(actual_start_at, planned_start_at)'), '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate(DB::raw('COALESCE(actual_start_at, planned_start_at)'), '<=', $filters['to']);
        }

        $rows = $query->orderBy('mission_id')->get();
        $statuses = ['planned' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0, 'failed' => 0];
        foreach ($rows->countBy('mission_status') as $status => $count) {
            if (array_key_exists((string) $status, $statuses)) {
                $statuses[(string) $status] = $count;
            }
        }

        return [
            'missions' => ['total' => $rows->count(), 'by_status' => $statuses],
            'trees' => [
                'total' => $this->sum($rows, 'tree_count'),
                'validated' => $this->sum($rows, 'validated_tree_count'),
                'unvalidated' => $this->sum($rows, 'unvalidated_tree_count'),
                'rejected' => $this->sum($rows, 'rejected_tree_count'),
            ],
            'species' => ['distinct' => $this->distinctSpecies($rows->pluck('mission_id')->all())],
            'validation' => [
                'sessions' => $this->sum($rows, 'validation_session_count'),
                'open_sessions' => $this->sum($rows, 'open_validation_session_count'),
                'completed_sessions' => $this->sum($rows, 'completed_validation_session_count'),
                'ground_truth_records' => $this->sum($rows, 'ground_truth_count'),
            ],
            'processing' => [
                'jobs' => $this->sum($rows, 'processing_job_count'),
                'queued' => $this->sum($rows, 'queued_processing_job_count'),
                'running' => $this->sum($rows, 'running_processing_job_count'),
                'completed' => $this->sum($rows, 'completed_processing_job_count'),
                'failed' => $this->sum($rows, 'failed_processing_job_count'),
                'cancelled' => $this->sum($rows, 'cancelled_processing_job_count'),
            ],
        ];
    }

    private function siteId(User $actor, ?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return SurveySite::query()->where('organization_id', $actor->organization_id)->findOrFail($id)->site_id;
    }

    private function missionId(User $actor, ?string $id, ?string $siteId): ?string
    {
        if ($id === null) {
            return null;
        }

        $query = SurveyMission::query()
            ->whereHas('site', fn (Builder $site) => $site->where('organization_id', $actor->organization_id));
        $mission = $this->operatorScope->missions($query, $actor)->findOrFail($id);
        if ($siteId !== null && $mission->site_id !== $siteId) {
            throw (new ModelNotFoundException)->setModel(SurveyMission::class, [$id]);
        }

        return $mission->mission_id;
    }

    /** @return list<string>|null */
    private function allowedMissionIds(User $actor): ?array
    {
        if (! $this->operatorScope->appliesTo($actor)) {
            return null;
        }

        return $this->operatorScope->missions(SurveyMission::query(), $actor)
            ->pluck('mission_id')->all();
    }

    private function sum($rows, string $column): int
    {
        return (int) $rows->sum(fn ($row): int => (int) $row->{$column});
    }

    /** @param list<string> $missionIds */
    private function distinctSpecies(array $missionIds): int
    {
        if ($missionIds === []) {
            return 0;
        }

        return DB::table('tree_observations')->whereIn('mission_id', $missionIds)
            ->whereNull('deleted_at')->whereNotNull('final_species_id')
            ->distinct()->count('final_species_id');
    }
}
