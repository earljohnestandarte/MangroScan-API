<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CanopyHeightEstimation extends Model
{
    use HasUuids;

    protected $primaryKey = 'height_estimation_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['height_meters' => 'decimal:2', 'height_confidence_score' => 'decimal:4', 'is_final' => 'boolean', 'created_at' => 'datetime'];
    }
}
