<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnvironmentLog extends Model
{
    use HasUuids;

    protected $primaryKey = 'environment_log_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'flight_session_id',
        'recorded_at',
        'weather_condition',
        'wind_speed_mps',
        'temperature_celsius',
        'humidity_percent',
        'visibility_status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'wind_speed_mps' => 'decimal:2',
            'temperature_celsius' => 'decimal:2',
            'humidity_percent' => 'decimal:2',
        ];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(
            FlightSession::class,
            'flight_session_id',
            'flight_session_id'
        );
    }
}
