<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SensorDataset extends Model
{
    use HasUuids, SoftDeletes;

    protected $primaryKey = 'sensor_dataset_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'recorded_start_at' => 'datetime', 'recorded_end_at' => 'datetime'];
    }
}
