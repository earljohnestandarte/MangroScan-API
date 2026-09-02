<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class GroundTruthTreeRecord extends Model
{
    use HasUuids;

    protected $primaryKey = 'ground_truth_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'validation_session_id',
        'field_code',
        'species_id',
        'ground_location',
        'measured_height_meters',
        'estimated_age_years',
        'diameter_cm',
        'crown_diameter_m',
        'health_status',
        'is_tree',
        'photo_path',
        'remarks',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'measured_height_meters' => 'decimal:2',
            'estimated_age_years' => 'decimal:2',
            'diameter_cm' => 'decimal:2',
            'crown_diameter_m' => 'decimal:2',
            'is_tree' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function scopeWithGroundLocationGeoJson(Builder $query): Builder
    {
        if (DB::getDriverName() === 'pgsql') {
            $query->select('ground_truth_tree_records.*')
                ->selectRaw('ST_AsGeoJSON(ground_location)::json AS ground_location_geojson');
        }

        return $query;
    }

    public function validationSession(): BelongsTo
    {
        return $this->belongsTo(ValidationSession::class, 'validation_session_id', 'validation_session_id');
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(MangroveSpecies::class, 'species_id', 'species_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ValidationMatch::class, 'ground_truth_id', 'ground_truth_id');
    }
}
