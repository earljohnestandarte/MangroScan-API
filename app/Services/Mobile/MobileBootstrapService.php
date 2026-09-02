<?php

namespace App\Services\Mobile;

use App\Models\FlightSession;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Auth\DroneOperatorScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MobileBootstrapService
{
    public function __construct(private readonly DroneOperatorScope $operatorScope) {}

    /**
     * @return array{
     *     missions: Collection<int, SurveyMission>,
     *     flights: Collection<int, FlightSession>,
     *     tombstones: Collection<int, array{entity: string, id: string, deleted_at: string}>,
     *     serverTime: CarbonImmutable
     * }
     */
    public function snapshot(User $actor, ?CarbonImmutable $after): array
    {
        return DB::transaction(function () use ($actor, $after): array {
            $serverTime = CarbonImmutable::now('UTC');
            $afterValue = $after === null ? null : $this->databaseTimestamp($after);
            $serverTimeValue = $this->databaseTimestamp($serverTime);
            $missionScope = fn (Builder $query): Builder => $query
                ->whereHas('site', fn (Builder $site) => $site
                    ->where('organization_id', $actor->organization_id));

            $missionQuery = SurveyMission::query()->where($missionScope);
            $missions = $this->operatorScope->missions($missionQuery, $actor)
                ->when($afterValue, fn (Builder $query) => $query->where('updated_at', '>', $afterValue))
                ->where('updated_at', '<=', $serverTimeValue)
                ->orderBy('mission_id')
                ->get();

            $flightQuery = FlightSession::query()
                ->withLocationGeoJson()
                ->whereHas('mission.site', fn (Builder $site) => $site
                    ->where('organization_id', $actor->organization_id));
            $flights = $this->operatorScope->flights($flightQuery, $actor)
                ->when($afterValue, fn (Builder $query) => $query->where('updated_at', '>', $afterValue))
                ->where('updated_at', '<=', $serverTimeValue)
                ->orderBy('flight_session_id')
                ->get();

            $tombstones = collect();

            if ($after !== null) {
                $tombstoneQuery = SurveyMission::query()
                    ->onlyTrashed()
                    ->whereHas('site', fn (Builder $site) => $site
                        ->where('organization_id', $actor->organization_id));
                $tombstones = $this->operatorScope->missions($tombstoneQuery, $actor)
                    ->where('deleted_at', '>', $afterValue)
                    ->where('deleted_at', '<=', $serverTimeValue)
                    ->orderBy('mission_id')
                    ->get(['mission_id', 'deleted_at'])
                    ->map(fn (SurveyMission $mission): array => [
                        'entity' => 'mission',
                        'id' => $mission->mission_id,
                        'deleted_at' => $mission->deleted_at->toIso8601String(),
                    ]);
            }

            return compact('missions', 'flights', 'tombstones', 'serverTime');
        });
    }

    private function databaseTimestamp(CarbonImmutable $value): string
    {
        return DB::getDriverName() === 'pgsql'
            ? $value->utc()->toIso8601String()
            : $value->utc()->format('Y-m-d H:i:s');
    }
}
