<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class SurveySite extends Model
{
    use HasUuids, SoftDeletes;

    protected $primaryKey = 'site_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'site_name',
        'site_code',
        'description',
        'province',
        'city_municipality',
        'barangay',
        'center_point',
        'area_hectares',
        'environment_type',
        'access_notes',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'area_hectares' => 'decimal:4',
        ];
    }

    public function scopeWithCenterPointGeoJson(Builder $query): Builder
    {
        if (DB::getDriverName() === 'pgsql') {
            $query
                ->select('survey_sites.*')
                ->selectRaw('ST_AsGeoJSON(center_point)::json AS center_point_geojson');
        }

        return $query;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'organization_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function boundaries(): HasMany
    {
        return $this->hasMany(SiteBoundary::class, 'site_id', 'site_id');
    }

    public function monitoringPlots(): HasMany
    {
        return $this->hasMany(MonitoringPlot::class, 'site_id', 'site_id');
    }

    public function accessPermissions(): HasMany
    {
        return $this->hasMany(SiteAccessPermission::class, 'site_id', 'site_id');
    }

    public function missions(): HasMany
    {
        return $this->hasMany(SurveyMission::class, 'site_id', 'site_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'site_id', 'site_id');
    }

    public function validationSessions(): HasMany
    {
        return $this->hasMany(ValidationSession::class, 'site_id', 'site_id');
    }
}
