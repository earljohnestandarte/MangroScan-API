<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SensorCalibration extends Model
{
    use HasUuids;

    protected $primaryKey = 'calibration_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'sensor_id',
        'calibration_date',
        'calibration_method',
        'calibration_file_path',
        'calibration_notes',
        'is_valid',
    ];

    protected function casts(): array
    {
        return [
            'calibration_date' => 'date',
            'is_valid' => 'boolean',
        ];
    }

    public function sensor(): BelongsTo
    {
        return $this->belongsTo(
            DroneSensor::class,
            'sensor_id',
            'sensor_id'
        );
    }
}