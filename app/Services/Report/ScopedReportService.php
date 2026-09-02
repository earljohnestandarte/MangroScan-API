<?php

namespace App\Services\Report;

use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ScopedReportService
{
    public function find(User $actor, string $id, bool $lock = false): Report
    {
        $query = Report::query()
            ->whereColumn('reports.site_id', 'survey_missions.site_id')
            ->join('survey_missions', 'survey_missions.mission_id', '=', 'reports.mission_id')
            ->whereNull('survey_missions.deleted_at')
            ->whereHas('site', fn (Builder $query) => $query
                ->where('organization_id', $actor->organization_id))
            ->whereHas('mission.site', fn (Builder $query) => $query
                ->where('organization_id', $actor->organization_id))
            ->select('reports.*');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }
}
