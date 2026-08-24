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
            ->whereHas('mission', fn (Builder $query) => $query
                ->whereColumn('survey_missions.site_id', 'validation_sessions.site_id')
                ->whereHas('site', fn (Builder $query) => $query
                    ->where('organization_id', $actor->organization_id)))
            ->whereHas('validator', fn (Builder $query) => $query
                ->where('organization_id', $actor->organization_id))
            ->where(function (Builder $query): void {
                $query->whereNull('plot_id')
                    ->orWhereHas('plot', fn (Builder $query) => $query
                        ->whereColumn('monitoring_plots.site_id', 'validation_sessions.site_id'));
            })
            ->findOrFail($id);
    }
}
