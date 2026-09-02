<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DroneSensor extends Model
{
    use HasUuids;

    protected $primaryKey = 'sensor_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'drone_id',
        'sensor_name',
        'sensor_type',
        'manufacturer',
        'model',
        'serial_number',
        'resolution',
        'range_meters',
        'calibration_required',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'range_meters' => 'decimal:2',
            'calibration_required' => 'boolean',
        ];
    }

    public function drone(): BelongsTo
    {
        return $this->belongsTo(
            Drone::class,
            'drone_id',
            'drone_id'
        );
    }

    public function calibrations(): HasMany
    {
        return $this->hasMany(
            SensorCalibration::class,
            'sensor_id',
            'sensor_id'
        );
    }
}