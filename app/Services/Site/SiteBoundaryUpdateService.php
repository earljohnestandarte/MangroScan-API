<?php

namespace App\Services\Site;

use App\Models\SiteBoundary;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SiteBoundaryUpdateService
{
    private const FIELDS = ['boundary_name', 'boundary_type', 'source'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string,mixed> $data */
    public function update(User $actor, SiteBoundary $authorized, array $data, ?string $ip, ?string $agent, ?string $requestId): SiteBoundary
    {
        return DB::transaction(function () use ($actor, $authorized, $data, $ip, $agent, $requestId): SiteBoundary {
            $boundary = SiteBoundary::query()->lockForUpdate()->findOrFail($authorized->boundary_id);
            $old = $this->snapshot(SiteBoundary::query()->withBoundaryGeoJson()->findOrFail($boundary->boundary_id));
            $boundary->fill(Arr::only($data, self::FIELDS))->save();
            if (array_key_exists('boundary_geom', $data)) {
                $this->updateGeometry($boundary->boundary_id, $data['boundary_geom']);
            }
            $updated = SiteBoundary::query()->withBoundaryGeoJson()->findOrFail($boundary->boundary_id);
            $this->auditLogger->record('boundary.update', 'site_boundaries', $boundary->boundary_id, $actor->user_id, $old, $this->snapshot($updated), $ip, $agent, $requestId);

            return $updated;
        });
    }

    /** @param array<string,mixed> $geometry */
    private function updateGeometry(string $id, array $geometry): void
    {
        $json = json_encode($geometry, JSON_THROW_ON_ERROR);
        if (DB::getDriverName() === 'pgsql') {
            if (! DB::scalar('SELECT ST_IsValid(ST_SetSRID(ST_GeomFromGeoJSON(?),4326))', [$json])) {
                throw ValidationException::withMessages(['boundary_geom' => ['The boundary geometry is not a valid polygon.']]);
            }
            DB::update('UPDATE site_boundaries SET boundary_geom=ST_SetSRID(ST_GeomFromGeoJSON(?),4326) WHERE boundary_id=?', [$json, $id]);
        } else {
            DB::table('site_boundaries')->where('boundary_id', $id)->update(['boundary_geom' => $json]);
        }
    }

    /** @return array<string,mixed> */
    private function snapshot(SiteBoundary $boundary): array
    {
        $geometry = $boundary->boundary_geojson ?? $boundary->boundary_geom;
        if (is_string($geometry)) {
            $geometry = json_decode($geometry, true, flags: JSON_THROW_ON_ERROR);
        }

        return [...$boundary->only(self::FIELDS), 'boundary_geom' => $geometry];
    }
}
