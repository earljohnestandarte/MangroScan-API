<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AgeEstimation extends Model
{
    use HasUuids;

    protected $primaryKey = 'age_estimation_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['estimated_age_years' => 'decimal:2', 'min_estimated_age_years' => 'decimal:2', 'max_estimated_age_years' => 'decimal:2', 'confidence_score' => 'decimal:4', 'is_final' => 'boolean', 'created_at' => 'datetime'];
    }
}
