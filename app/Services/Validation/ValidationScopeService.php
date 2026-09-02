<?php

namespace App\Services\Validation;

use App\Models\MangroveSpecies;
use App\Models\SurveyMission;
use App\Models\User;
use App\Models\ValidationSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ValidationScopeService
{
    /**
     * @return array{
     *     missions: Collection<int, array<string, mixed>>,
     *     species: Collection<int, array<string, mixed>>,
     *     assignees: Collection<int, array<string, mixed>>,
     *     sessions: Collection<int, ValidationSession>
     * }
     */
    public function options(User $actor): array
    {
        return [
            'missions' => $this->missions($actor),
            'species' => $this->species(),
            'assignees' => $this->assignees($actor),
            'sessions' => $this->sessions($actor),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function missions(User $actor): Collection
    {
        return SurveyMission::query()
            ->with([
                'site:site_id,site_code,site_name',
                'site.monitoringPlots' => fn ($query) => $query
                    ->select(['plot_id', 'site_id', 'plot_code', 'plot_name'])
                    ->orderBy('plot_code')
                    ->orderBy('plot_id'),
            ])
            ->whereHas('site', fn (Builder $query) => $query
                ->where('organization_id', $actor->organization_id))
            ->orderBy('mission_code')
            ->orderBy('mission_id')
            ->get(['mission_id', 'site_id', 'mission_code', 'mission_title', 'mission_status'])
            ->map(fn (SurveyMission $mission): array => [
                'mission_id' => $mission->mission_id,
                'mission_code' => $mission->mission_code,
                'mission_title' => $mission->mission_title,
                'status' => $mission->mission_status,
                'site' => [
                    'site_id' => $mission->site->site_id,
                    'site_code' => $mission->site->site_code,
                    'site_name' => $mission->site->site_name,
                ],
                'plots' => $mission->site->monitoringPlots->map(fn ($plot): array => [
                    'plot_id' => $plot->plot_id,
                    'plot_code' => $plot->plot_code,
                    'plot_name' => $plot->plot_name,
                ])->values()->all(),
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function species(): Collection
    {
        return MangroveSpecies::query()
            ->where('is_active', true)
            ->orderBy('scientific_name')
            ->orderBy('species_id')
            ->get(['species_id', 'scientific_name', 'common_name', 'local_name'])
            ->map(fn (MangroveSpecies $species): array => [
                'species_id' => $species->species_id,
                'scientific_name' => $species->scientific_name,
                'common_name' => $species->common_name,
                'local_name' => $species->local_name,
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function assignees(User $actor): Collection
    {
        return User::query()
            ->where('organization_id', $actor->organization_id)
            ->where('status', 'active')
            ->whereHas('roles', function (Builder $query) use ($actor): void {
                $query
                    ->where(function (Builder $query) use ($actor): void {
                        $query
                            ->whereNull('roles.organization_id')
                            ->orWhere('roles.organization_id', $actor->organization_id);
                    })
                    ->whereHas('permissions', fn (Builder $query) => $query
                        ->where('permission_code', 'validation.create'));
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('user_id')
            ->get(['user_id', 'first_name', 'middle_name', 'last_name', 'position_title'])
            ->map(fn (User $user): array => [
                'user_id' => $user->user_id,
                'display_name' => collect([$user->first_name, $user->middle_name, $user->last_name])
                    ->filter(fn (?string $part): bool => filled($part))
                    ->implode(' '),
                'position_title' => $user->position_title,
            ]);
    }

    /** @return Collection<int, ValidationSession> */
    private function sessions(User $actor): Collection
    {
        return ValidationSession::query()
            ->join('survey_missions', 'survey_missions.mission_id', '=', 'validation_sessions.mission_id')
            ->join('survey_sites', 'survey_sites.site_id', '=', 'survey_missions.site_id')
            ->whereColumn('validation_sessions.site_id', 'survey_missions.site_id')
            ->where('survey_sites.organization_id', $actor->organization_id)
            ->whereNull('survey_missions.deleted_at')
            ->whereNull('survey_sites.deleted_at')
            ->select('validation_sessions.*')
            ->orderByDesc('validation_sessions.validation_date')
            ->orderBy('validation_sessions.validation_session_id')
            ->get();
    }
}
