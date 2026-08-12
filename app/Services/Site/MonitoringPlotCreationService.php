<?php

namespace App\Services\Site;

use App\Exceptions\WorkflowConflictException;
use App\Models\MonitoringPlot;
use App\Models\SurveySite;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MonitoringPlotCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, SurveySite $site, array $data, ?string $ipAddress, ?string $userAgent, ?string $requestId): MonitoringPlot
    {
        return DB::transaction(function () use ($actor, $site, $data, $ipAddress, $userAgent, $requestId): MonitoringPlot {
            if (MonitoringPlot::withTrashed()->where('site_id', $site->site_id)->where('plot_code', $data['plot_code'])->exists()) {
                throw new WorkflowConflictException('A plot with this code already exists in the site.', [
                    'plot_code' => $data['plot_code'],
                ]);
            }

            $plotId = (string) Str::uuid();
            $now = Carbon::now();
            $attributes = [
                'plot_id' => $plotId,
                'site_id' => $site->site_id,
                'plot_code' => $data['plot_code'],
                'plot_name' => $data['plot_name'] ?? null,
                'area_square_meters' => $data['area_square_meters'] ?? null,
                'description' => $data['description'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $this->insert($attributes, $data['plot_geom']);
            $this->auditLogger->record(
                action: 'plot.create', tableName: 'monitoring_plots', recordId: $plotId,
                userId: $actor->user_id, oldValues: null,
                newValues: [...array_diff_key($attributes, array_flip(['created_at', 'updated_at'])), 'plot_geom' => $data['plot_geom']],
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return MonitoringPlot::query()->withPlotGeoJson()->findOrFail($plotId);
        });
    }

    /** @param array<string, mixed> $attributes @param array<string, mixed> $geometry */
    private function insert(array $attributes, array $geometry): void
    {
        $geoJson = json_encode($geometry, JSON_THROW_ON_ERROR);
        if (DB::getDriverName() !== 'pgsql') {
            DB::table('monitoring_plots')->insert([...$attributes, 'plot_geom' => $geoJson]);

            return;
        }

        if (! DB::scalar('SELECT ST_IsValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))', [$geoJson])) {
            throw ValidationException::withMessages(['plot_geom' => ['The plot geometry is not a valid polygon.']]);
        }

        DB::insert(
            'INSERT INTO monitoring_plots (plot_id, site_id, plot_code, plot_name, plot_geom, area_square_meters, description, created_at, updated_at) VALUES (?, ?, ?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?, ?, ?, ?)',
            [$attributes['plot_id'], $attributes['site_id'], $attributes['plot_code'], $attributes['plot_name'], $geoJson, $attributes['area_square_meters'], $attributes['description'], $attributes['created_at'], $attributes['updated_at']],
        );
    }
}
