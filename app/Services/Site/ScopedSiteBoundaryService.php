<?php

namespace App\Services\Site;

use App\Models\SiteBoundary;
use App\Models\User;

class ScopedSiteBoundaryService
{
    public function find(User $actor, string $boundaryId): SiteBoundary
    {
        return SiteBoundary::query()->withBoundaryGeoJson()
            ->whereHas('site', fn ($query) => $query->where('organization_id', $actor->organization_id))
            ->findOrFail($boundaryId);
    }
}
