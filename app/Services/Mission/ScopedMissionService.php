<?php

namespace App\Services\Mission;

use App\Models\SurveyMission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScopedMissionService
{
    public function find(User $actor, string $id): SurveyMission
    {
        return SurveyMission::query()->whereHas('site', fn (Builder $q) => $q->where('organization_id', $actor->organization_id))->findOrFail($id);
    }

    /** @return array{total:int,planned:int,flying:int,completed:int,aborted:int,failed:int} */
    public function flightSummary(SurveyMission $mission): array
    {
        $summary = ['total' => 0, 'planned' => 0, 'flying' => 0, 'completed' => 0, 'aborted' => 0, 'failed' => 0];
        if (! Schema::hasTable('flight_sessions')) {
            return $summary;
        }
        $counts = DB::table('flight_sessions')->where('mission_id', $mission->mission_id)->selectRaw('flight_status, COUNT(*) AS aggregate')->groupBy('flight_status')->pluck('aggregate', 'flight_status');
        $summary['total'] = (int) $counts->sum();
        foreach (array_keys($summary) as $status) {
            if ($status !== 'total') {
                $summary[$status] = (int) ($counts[$status] ?? 0);
            }
        }

        return $summary;
    }
}
