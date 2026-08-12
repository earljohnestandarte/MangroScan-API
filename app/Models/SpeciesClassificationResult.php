<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SpeciesClassificationResult extends Model
{
    use HasUuids;

    protected $primaryKey = 'classification_result_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['confidence_score' => 'decimal:4', 'rank_no' => 'integer', 'classification_basis' => 'array', 'is_final' => 'boolean', 'created_at' => 'datetime'];
    }
}
