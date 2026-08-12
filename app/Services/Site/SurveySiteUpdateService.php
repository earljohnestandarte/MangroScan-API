<?php

namespace App\Services\Site;

use App\Exceptions\WorkflowConflictException;
use App\Models\SurveySite;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SurveySiteUpdateService
{
    private const FIELDS = ['site_name', 'site_code', 'description', 'province', 'city_municipality', 'barangay', 'area_hectares', 'environment_type', 'access_notes'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function update(User $actor, SurveySite $authorizedSite, array $data, ?string $ipAddress, ?string $userAgent, ?string $requestId): SurveySite
    {
        return DB::transaction(function () use ($actor, $authorizedSite, $data, $ipAddress, $userAgent, $requestId): SurveySite {
            $site = SurveySite::query()->lockForUpdate()->findOrFail($authorizedSite->site_id);
            if (array_key_exists('site_code', $data)) {
                $this->reserveCode($site->site_id, $data['site_code']);
            }
            $old = $this->snapshot(SurveySite::query()->withCenterPointGeoJson()->findOrFail($site->site_id));
            $site->fill(Arr::only($data, self::FIELDS))->save();
            if (array_key_exists('center_point', $data)) {
                $this->updatePoint($site->site_id, $data['center_point']);
            }
            $updated = SurveySite::query()->withCenterPointGeoJson()->findOrFail($site->site_id);
            $this->auditLogger->record(
                action: 'site.update', tableName: 'survey_sites', recordId: $site->site_id, userId: $actor->user_id,
                oldValues: $old, newValues: $this->snapshot($updated), ipAddress: $ipAddress,
                userAgent: $userAgent, requestId: $requestId,
            );

            return $updated;
        });
    }

    private function reserveCode(string $siteId, string $code): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$code]);
        }
        if (SurveySite::withTrashed()->where('site_id', '!=', $siteId)->where('site_code', $code)->exists()) {
            throw new WorkflowConflictException('A site with this code already exists.', ['site_code' => $code]);
        }
    }

    /** @param array<string, mixed>|null $point */
    private function updatePoint(string $siteId, ?array $point): void
    {
        $json = $point === null ? null : json_encode($point, JSON_THROW_ON_ERROR);
        if (DB::getDriverName() === 'pgsql') {
            DB::update('UPDATE survey_sites SET center_point = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) WHERE site_id = ?', [$json, $siteId]);
        } else {
            DB::table('survey_sites')->where('site_id', $siteId)->update(['center_point' => $json]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(SurveySite $site): array
    {
        $point = $site->center_point_geojson ?? $site->center_point;
        if (is_string($point)) {
            $point = json_decode($point, true, flags: JSON_THROW_ON_ERROR);
        }

        return [...$site->only(self::FIELDS), 'center_point' => $point];
    }
}
