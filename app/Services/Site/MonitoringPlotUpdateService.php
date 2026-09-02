<?php

namespace App\Services\Site;

use App\Exceptions\WorkflowConflictException;
use App\Models\MonitoringPlot;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonitoringPlotUpdateService
{
    private const FIELDS = ['plot_code', 'plot_name', 'area_square_meters', 'description'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function update(User $actor, MonitoringPlot $authorized, array $data, ?string $ipAddress, ?string $userAgent, ?string $requestId): MonitoringPlot
    {
        return DB::transaction(function () use ($actor, $authorized, $data, $ipAddress, $userAgent, $requestId): MonitoringPlot {
            $plot = MonitoringPlot::query()->lockForUpdate()->findOrFail($authorized->plot_id);

            if (array_key_exists('plot_code', $data)) {
                $this->reserveCode($plot->plot_id, $plot->site_id, $data['plot_code']);
            }

            $old = $this->snapshot(MonitoringPlot::query()->withPlotGeoJson()->findOrFail($plot->plot_id));

            $plot->fill(Arr::only($data, self::FIELDS))->save();

            if (array_key_exists('plot_geom', $data)) {
                $this->updateGeometry($plot->plot_id, $data['plot_geom']);
            }

            if (($data['archive'] ?? false) === true) {
                $plot->delete();
            }

            $updated = MonitoringPlot::query()->withTrashed()->withPlotGeoJson()->findOrFail($plot->plot_id);

            $this->auditLogger->record(
                action: 'plot.update',
                tableName: 'monitoring_plots',
                recordId: $plot->plot_id,
                userId: $actor->user_id,
                oldValues: $old,
                newValues: $this->snapshot($updated),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $updated;
        });
    }

    private function reserveCode(string $plotId, string $siteId, string $code): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$code]);
        }
        if (MonitoringPlot::withTrashed()->where('plot_id', '!=', $plotId)->where('site_id', $siteId)->where('plot_code', $code)->exists()) {
            throw new WorkflowConflictException('A plot with this code already exists in the site.', ['plot_code' => $code]);
        }
    }

    /** @param array<string, mixed> $geometry */
    private function updateGeometry(string $id, array $geometry): void
    {
        $json = json_encode($geometry, JSON_THROW_ON_ERROR);
        if (DB::getDriverName() === 'pgsql') {
            if (! DB::scalar('SELECT ST_IsValid(ST_SetSRID(ST_GeomFromGeoJSON(?),4326))', [$json])) {
                throw ValidationException::withMessages(['plot_geom' => ['The plot geometry is not a valid polygon.']]);
            }
            DB::update('UPDATE monitoring_plots SET plot_geom=ST_SetSRID(ST_GeomFromGeoJSON(?),4326) WHERE plot_id=?', [$json, $id]);
        } else {
            DB::table('monitoring_plots')->where('plot_id', $id)->update(['plot_geom' => $json]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(MonitoringPlot $plot): array
    {
        $geometry = $plot->plot_geojson ?? $plot->plot_geom;
        if (is_string($geometry)) {
            $geometry = json_decode($geometry, true, flags: JSON_THROW_ON_ERROR);
        }
        return [...$plot->only(self::FIELDS), 'plot_geom' => $geometry, 'archived' => $plot->trashed()];
    }
}
