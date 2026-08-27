<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class FlightSession extends Model
{
    use HasUuids;

    protected $primaryKey = 'flight_session_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'mission_id',
        'drone_id',
        'pilot_user_id',
        'flight_code',
        'takeoff_location',
        'landing_location',
        'planned_altitude_meters',
        'actual_avg_altitude_meters',
        'started_at',
        'ended_at',
        'flight_duration_minutes',
        'flight_status',
        'quality_status',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'planned_altitude_meters' => 'decimal:2',
            'actual_avg_altitude_meters' => 'decimal:2',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'flight_duration_minutes' => 'decimal:2',
            'sync_version' => 'integer',
        ];
    }

    public function scopeWithLocationGeoJson(Builder $query): Builder
    {
        if (DB::getDriverName() === 'pgsql') {
            $query
                ->select('flight_sessions.*')
                ->selectRaw(
                    'ST_AsGeoJSON(takeoff_location)::json AS takeoff_location_geojson'
                )
                ->selectRaw(
                    'ST_AsGeoJSON(landing_location)::json AS landing_location_geojson'
                );
        }

        return $query;
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(
            SurveyMission::class,
            'mission_id',
            'mission_id'
        );
    }

    public function drone(): BelongsTo
    {
        return $this->belongsTo(
            Drone::class,
            'drone_id',
            'drone_id'
        );
    }

    public function pilot(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'pilot_user_id',
            'user_id'
        );
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(
            FlightChecklist::class,
            'flight_session_id',
            'flight_session_id'
        );
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(
            MediaAsset::class,
            'flight_session_id',
            'flight_session_id'
        );
    }

    public function environmentLogs(): HasMany
    {
        return $this->hasMany(
            EnvironmentLog::class,
            'flight_session_id',
            'flight_session_id'
        );
    }

    public function batteryUsages(): HasMany
    {
        return $this->hasMany(
            BatteryUsage::class,
            'flight_session_id',
            'flight_session_id'
        );
    }
}