<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ScopedMediaAssetService
{
    public function find(User $actor, string $id): MediaAsset
    {
        return MediaAsset::query()
            ->withCaptureLocationGeoJson()
            ->whereHas('flight.mission.site', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            })
            ->findOrFail($id);
    }
}
