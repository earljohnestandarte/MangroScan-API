<?php

namespace App\Services\Validation;

use App\Models\User;
use App\Models\ValidationSession;
use Illuminate\Database\Eloquent\Builder;

class ScopedValidationSessionService
{
    public function find(User $actor, string $id): ValidationSession
    {
        return ValidationSession::query()
            ->whereHas('mission.site', fn (Builder $query) => $query->where('organization_id', $actor->organization_id))
            ->findOrFail($id);
    }
}
