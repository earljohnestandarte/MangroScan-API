<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class TreeObservation extends Model
{
    use HasUuids, SoftDeletes;

    protected $primaryKey = 'tree_observation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tree_entity_id',
        'mission_id',
        'flight_session_id',
        'model_run_id',
        'source_media_id',
        'tree_code',
        'tree_location',
        'crown_polygon',
        'bounding_box',
        'detection_confidence',
        'final_species_id',
        'final_height_meters',
        'final_estimated_age_years',
        'validation_status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bounding_box' => 'array',
            'detection_confidence' => 'decimal:4',
            'final_height_meters' => 'decimal:2',
            'final_estimated_age_years' => 'decimal:2',
        ];
    }

    public function scopeWithGeometryGeoJson(Builder $query): Builder
    {
        if (DB::getDriverName() === 'pgsql') {
            $query->select('tree_observations.*')
                ->selectRaw('ST_AsGeoJSON(tree_location)::json AS tree_location_geojson')
                ->selectRaw('ST_AsGeoJSON(crown_polygon)::json AS crown_polygon_geojson');
        }

        return $query;
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(SurveyMission::class, 'mission_id', 'mission_id');
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(FlightSession::class, 'flight_session_id', 'flight_session_id');
    }

    public function finalSpecies(): BelongsTo
    {
        return $this->belongsTo(MangroveSpecies::class, 'final_species_id', 'species_id');
    }
}
