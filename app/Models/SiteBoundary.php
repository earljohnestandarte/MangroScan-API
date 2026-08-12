<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class SiteBoundary extends Model
{
    use HasUuids;

    protected $primaryKey = 'boundary_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'site_id',
        'boundary_name',
        'boundary_type',
        'boundary_geom',
        'source',
        'created_by',
    ];

    public function scopeWithBoundaryGeoJson(Builder $query): Builder
    {
        if (DB::getDriverName() === 'pgsql') {
            $query
                ->select('site_boundaries.*')
                ->selectRaw('ST_AsGeoJSON(boundary_geom)::json AS boundary_geojson');
        }

        return $query;
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SurveySite::class, 'site_id', 'site_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
