<?php

namespace App\Services\Drone;

use App\Models\Drone;
use App\Models\User;

class ScopedDroneService
{
    public function find(User $actor, string $droneId): Drone
    {
        return Drone::query()
            ->where('organization_id', $actor->organization_id)
            ->findOrFail($droneId);
    }
}
