<?php

namespace App\Services\Site;

use App\Models\SurveySite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScopedSurveySiteService
{
    public function find(User $actor, string $siteId): SurveySite
    {
        return SurveySite::query()
            ->withCenterPointGeoJson()
            ->where('organization_id', $actor->organization_id)
            ->findOrFail($siteId);
    }

    /**
     * @return array{boundaries: int, plots: int, access_permissions: int, missions: int}
     */
    public function summaryCounts(SurveySite $site): array
    {
        return [
            'boundaries' => $this->countChildren('site_boundaries', $site->site_id),
            'plots' => $this->countChildren('monitoring_plots', $site->site_id, softDeletes: true),
            'access_permissions' => $this->countChildren('site_access_permissions', $site->site_id),
            'missions' => $this->countChildren('survey_missions', $site->site_id, softDeletes: true),
        ];
    }

    private function countChildren(string $table, string $siteId, bool $softDeletes = false): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table)->where('site_id', $siteId);

        if ($softDeletes) {
            $query->whereNull('deleted_at');
        }

        return $query->count();
    }
}
