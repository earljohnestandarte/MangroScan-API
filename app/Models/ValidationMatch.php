<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValidationMatch extends Model
{
    use HasUuids;

    protected $primaryKey = 'validation_match_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ground_truth_id',
        'tree_observation_id',
        'match_status',
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
            'distance_error_meters' => 'decimal:4',
            'species_correct' => 'boolean',
            'height_error_meters' => 'decimal:4',
            'age_error_years' => 'decimal:4',
            'validated_at' => 'datetime',
        ];
    }

    public function groundTruthRecord(): BelongsTo
    {
        return $this->belongsTo(GroundTruthTreeRecord::class, 'ground_truth_id', 'ground_truth_id');
    }

    public function treeObservation(): BelongsTo
    {
        return $this->belongsTo(TreeObservation::class, 'tree_observation_id', 'tree_observation_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by', 'user_id');
    }
}
