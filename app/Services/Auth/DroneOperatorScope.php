<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DroneOperatorScope
{
    public function appliesTo(User $actor): bool
    {
        return $actor->roles()
            ->where(function (Builder $query) use ($actor): void {
                $query
                    ->whereNull('roles.organization_id')
                    ->orWhere('roles.organization_id', $actor->organization_id);
            })
            ->where('role_code', 'drone_operator')
            ->exists();
    }

    public function missions(Builder $query, User $actor): Builder
    {
        if (! $this->appliesTo($actor)) {
            return $query;
        }

        return $query
            ->whereNotNull('approved_by')
            ->where(function (Builder $assignment) use ($actor): void {
                $assignment
                    ->whereHas('teamMembers', fn (Builder $team) => $team
                        ->where('user_id', $actor->user_id))
                    ->orWhereHas('flightSessions', fn (Builder $flights) => $flights
                        ->where('pilot_user_id', $actor->user_id));
            });
    }

    public function flights(Builder $query, User $actor): Builder
    {
        if (! $this->appliesTo($actor)) {
            return $query;
        }

        return $query
            ->where('pilot_user_id', $actor->user_id)
            ->whereHas('mission', fn (Builder $mission) => $mission->whereNotNull('approved_by'));
    }

    public function media(Builder $query, User $actor): Builder
    {
        if (! $this->appliesTo($actor)) {
            return $query;
        }

        return $query->whereHas('flight', fn (Builder $flight) => $flight
            ->where('pilot_user_id', $actor->user_id)
            ->whereHas('mission', fn (Builder $mission) => $mission->whereNotNull('approved_by')));
    }
}
