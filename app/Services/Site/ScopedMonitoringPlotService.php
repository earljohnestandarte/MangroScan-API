<?php

namespace App\Services\Site;

use App\Models\MonitoringPlot;
use App\Models\User;

class ScopedMonitoringPlotService
{
    public function find(User $actor, string $plotId): MonitoringPlot
    {
        return MonitoringPlot::query()->withPlotGeoJson()
            ->whereHas('site', fn($query) => $query->where('organization_id', $actor->organization_id))
            ->findOrFail($plotId);
    }
}
