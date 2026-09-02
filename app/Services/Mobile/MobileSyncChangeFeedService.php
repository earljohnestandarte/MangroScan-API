<?php

namespace App\Services\Mobile;

use App\Http\Resources\FlightChecklistResource;
use App\Http\Resources\FlightSessionResource;
use App\Http\Resources\GroundTruthTreeRecordResource;
use App\Http\Resources\MediaAssetResource;
use App\Models\FlightChecklist;
use App\Models\FlightSession;
use App\Models\GroundTruthTreeRecord;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Auth\DroneOperatorScope;
use App\Services\Auth\EffectiveAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class MobileSyncChangeFeedService
{
    public function __construct(
        private readonly DroneOperatorScope $operatorScope,
        private readonly EffectiveAccessService $access,
    ) {}

    /** @return list<array<string, mixed>> */
    public function changes(User $actor, ?CarbonImmutable $after, CarbonImmutable $through): array
    {
        $afterValue = $after === null ? null : $this->databaseTimestamp($after);
        $throughValue = $this->databaseTimestamp($through);
        $permissions = $this->access->rolesAndPermissions($actor)['permissions'];
        $flights = collect();
        $checklists = collect();
        $media = collect();
        $deletedMedia = collect();
        $validation = collect();

        if ($this->hasAny($permissions, ['flights.read', 'flights.start', 'flights.complete', 'checklists.submit'])) {
            $flightQuery = FlightSession::query()
                ->withLocationGeoJson()
                ->whereHas('mission.site', fn (Builder $site) => $site
                    ->where('organization_id', $actor->organization_id));
            $flights = $this->operatorScope->flights($flightQuery, $actor)
                ->when($afterValue, fn (Builder $query) => $query->where('updated_at', '>', $afterValue))
                ->where('updated_at', '<=', $throughValue)
                ->orderBy('updated_at')->orderBy('flight_session_id')->get();

            $allVisibleFlightIds = $this->operatorScope->flights(
                FlightSession::query()->whereHas('mission.site', fn (Builder $site) => $site
                    ->where('organization_id', $actor->organization_id)),
                $actor,
            )->pluck('flight_session_id');
            $checklists = FlightChecklist::query()->whereIn('flight_session_id', $allVisibleFlightIds)
                ->when($afterValue, fn (Builder $query) => $query->where('created_at', '>', $afterValue))
                ->where('created_at', '<=', $throughValue)
                ->orderBy('created_at')->orderBy('checklist_id')->get();
        }

        if ($this->hasAny($permissions, ['media.read', 'media.quality_review', 'media.delete'])) {
            $mediaQuery = MediaAsset::query()->withCaptureLocationGeoJson()
                ->whereHas('flight.mission.site', fn (Builder $site) => $site
                    ->where('organization_id', $actor->organization_id));
            $media = $this->operatorScope->media($mediaQuery, $actor)
                ->when($afterValue, fn (Builder $query) => $query->where('updated_at', '>', $afterValue))
                ->where('updated_at', '<=', $throughValue)
                ->orderBy('updated_at')->orderBy('media_asset_id')->get();

            $deletedQuery = MediaAsset::onlyTrashed()
                ->whereHas('flight.mission.site', fn (Builder $site) => $site
                    ->where('organization_id', $actor->organization_id));
            $deletedMedia = $this->operatorScope->media($deletedQuery, $actor)
                ->when($afterValue, fn (Builder $query) => $query->where('deleted_at', '>', $afterValue))
                ->where('deleted_at', '<=', $throughValue)
                ->orderBy('deleted_at')->orderBy('media_asset_id')->get();
        }

        if ($this->hasAny($permissions, ['validation.read', 'validation.record_ground_truth'])) {
            $validation = GroundTruthTreeRecord::query()->withGroundLocationGeoJson()
                ->whereHas('validationSession.mission.site', fn (Builder $site) => $site
                    ->where('organization_id', $actor->organization_id))
                ->when($afterValue, fn (Builder $query) => $query->where('created_at', '>', $afterValue))
                ->where('created_at', '<=', $throughValue)
                ->orderBy('created_at')->orderBy('ground_truth_id')->get();
        }

        $changes = [];
        foreach ($flights as $flight) {
            $changes[] = $this->upsert('flight_session', $flight->flight_session_id, (int) $flight->sync_version,
                (new FlightSessionResource($flight))->resolve(request()), $flight->updated_at?->toIso8601String());
        }
        foreach ($checklists as $checklist) {
            $flightVersion = (int) (FlightSession::query()->whereKey($checklist->flight_session_id)->value('sync_version') ?? 1);
            $changes[] = $this->upsert('flight_checklist', $checklist->checklist_id, $flightVersion,
                (new FlightChecklistResource($checklist))->resolve(request()), $checklist->created_at?->toIso8601String());
        }
        foreach ($media as $asset) {
            $changes[] = $this->upsert('media', $asset->media_asset_id, (int) $asset->sync_version,
                (new MediaAssetResource($asset))->resolve(request()), $asset->updated_at?->toIso8601String());
        }
        foreach ($deletedMedia as $asset) {
            $changes[] = [
                'entity' => 'media',
                'operation' => 'delete',
                'server_id' => $asset->media_asset_id,
                'server_version' => (int) $asset->sync_version,
                'changed_at' => $asset->deleted_at?->toIso8601String(),
                'data' => null,
            ];
        }
        foreach ($validation as $record) {
            $changes[] = $this->upsert('validation_record', $record->ground_truth_id, 1,
                (new GroundTruthTreeRecordResource($record))->resolve(request()), $record->created_at?->toIso8601String());
        }

        return collect($changes)->sortBy([
            ['changed_at', 'asc'], ['entity', 'asc'], ['server_id', 'asc'],
        ])->values()->all();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function upsert(string $entity, string $id, int $version, array $data, ?string $changedAt): array
    {
        return [
            'entity' => $entity,
            'operation' => 'upsert',
            'server_id' => $id,
            'server_version' => $version,
            'changed_at' => $changedAt,
            'data' => $data,
        ];
    }

    private function databaseTimestamp(CarbonImmutable $value): string
    {
        return config('database.default') === 'pgsql'
            ? $value->utc()->format('Y-m-d\\TH:i:s.uP')
            : $value->utc()->format('Y-m-d H:i:s');
    }

    /** @param list<string> $permissions @param list<string> $required */
    private function hasAny(array $permissions, array $required): bool
    {
        return array_intersect($permissions, $required) !== [];
    }
}
