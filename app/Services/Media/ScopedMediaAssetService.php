<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Auth\DroneOperatorScope;
use Illuminate\Database\Eloquent\Builder;

class ScopedMediaAssetService
{
    public function __construct(private readonly DroneOperatorScope $operatorScope) {}

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
        $query = MediaAsset::query()
            ->whereHas('flight.mission.site', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            });

        return $this->operatorScope->media($query, $actor);
    }
}
