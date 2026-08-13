<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class MonitoringPlot extends Model
{
    use HasUuids, SoftDeletes;

    protected $primaryKey = 'plot_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['site_id', 'plot_code', 'plot_name', 'plot_geom', 'area_square_meters', 'description'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['area_square_meters' => 'decimal:2'];
    }

    public function scopeWithPlotGeoJson(Builder $query): Builder
    {
        if (DB::getDriverName() === 'pgsql') {
            $query->select('monitoring_plots.*')->selectRaw('ST_AsGeoJSON(plot_geom)::json AS plot_geojson');
        }

        return $query;
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SurveySite::class, 'site_id', 'site_id');
    }

    public function validationSessions(): HasMany
    {
        return $this->hasMany(ValidationSession::class, 'plot_id', 'plot_id');
    }
}
