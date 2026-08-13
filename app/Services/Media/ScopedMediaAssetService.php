<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ScopedMediaAssetService
{
    public function find(User $actor, string $id): MediaAsset
    {
        return $this->query($actor)
            ->withCaptureLocationGeoJson()
            ->findOrFail($id);
    }

    public function findForUpdate(User $actor, string $id): MediaAsset
    {
        return $this->query($actor)
            ->lockForUpdate()
            ->findOrFail($id);
    }

    private function query(User $actor): Builder
    {
        return MediaAsset::query()
            ->whereHas('flight.mission.site', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            });
    }
}
