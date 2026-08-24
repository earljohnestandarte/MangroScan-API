<?php

namespace App\Services\Validation;

use App\Models\User;
use App\Models\ValidationSession;
use App\Services\Mission\ScopedMissionService;
use App\Services\Site\ScopedSurveySiteService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ValidationSessionIndexService
{
    public function __construct(
        private readonly ScopedMissionService $missions,
        private readonly ScopedSurveySiteService $sites,
    ) {}

    /**
     * @param  array{mission_id?: string|null, site_id?: string|null, status?: string|null, page?: int}  $filters
     * @return LengthAwarePaginator<ValidationSession>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $query = ValidationSession::query()
            ->join('survey_missions', 'survey_missions.mission_id', '=', 'validation_sessions.mission_id')
            ->join('survey_sites', 'survey_sites.site_id', '=', 'survey_missions.site_id')
            ->leftJoin('monitoring_plots', 'monitoring_plots.plot_id', '=', 'validation_sessions.plot_id')
            ->whereColumn('validation_sessions.site_id', 'survey_missions.site_id')
            ->where('survey_sites.organization_id', $actor->organization_id)
            ->whereNull('survey_missions.deleted_at')
            ->whereNull('survey_sites.deleted_at')
            ->where(function (Builder $query): void {
                $query->whereNull('validation_sessions.plot_id')
                    ->orWhere(function (Builder $query): void {
                        $query->whereColumn('monitoring_plots.site_id', 'validation_sessions.site_id')
                            ->whereNull('monitoring_plots.deleted_at');
                    });
            });

        if (! empty($filters['mission_id'])) {
            $mission = $this->missions->find($actor, $filters['mission_id']);
            $query->where('validation_sessions.mission_id', $mission->mission_id);
        }

        if (! empty($filters['site_id'])) {
            $site = $this->sites->find($actor, $filters['site_id']);
            $query->where('validation_sessions.site_id', $site->site_id);
        }

        if (! empty($filters['status'])) {
            $query->where('validation_sessions.status', $filters['status']);
        }

        return $query
            ->select('validation_sessions.*')
            ->orderByDesc('validation_sessions.validation_date')
            ->orderBy('validation_sessions.validation_session_id')
            ->paginate(25);
    }
}
