<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class ValidationMatch extends Model
{
    use HasUuids;

    protected $primaryKey = 'validation_match_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'validation_session_id',
        'ground_truth_id',
        'tree_observation_id',
        'match_status',
        'accepted_species_id',
        'accepted_height_m',
        'accepted_age_years',
        'corrected_geometry',
        'notes',
        'validation_evidence',
        'distance_error_meters',
        'species_correct',
        'height_error_meters',
        'age_error_years',
        'validated_by',
        'validated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'accepted_height_m' => 'decimal:2',
            'accepted_age_years' => 'decimal:2',
            'validation_evidence' => 'array',
            'distance_error_meters' => 'decimal:4',
            'species_correct' => 'boolean',
            'height_error_meters' => 'decimal:4',
            'age_error_years' => 'decimal:4',
            'validated_at' => 'datetime',
        ];
    }

    public function scopeWithCorrectedGeometryGeoJson(Builder $query): Builder
    {
        if (DB::getDriverName() === 'pgsql') {
            $query->select('validation_matches.*')
                ->selectRaw('ST_AsGeoJSON(corrected_geometry)::json AS corrected_geometry_geojson');
        }

        return $query;
    }

    public function validationSession(): BelongsTo
    {
        return $this->belongsTo(ValidationSession::class, 'validation_session_id', 'validation_session_id');
    }

    public function groundTruthRecord(): BelongsTo
    {
        return $this->belongsTo(GroundTruthTreeRecord::class, 'ground_truth_id', 'ground_truth_id');
    }

    public function treeObservation(): BelongsTo
    {
        return $this->belongsTo(TreeObservation::class, 'tree_observation_id', 'tree_observation_id');
    }

    public function acceptedSpecies(): BelongsTo
    {
        return $this->belongsTo(MangroveSpecies::class, 'accepted_species_id', 'species_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by', 'user_id');
    }
}
