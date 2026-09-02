<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatteryUsage extends Model
{
    use HasUuids;

    protected $table = 'battery_usages';

    protected $primaryKey = 'battery_usage_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'flight_session_id',
        'battery_id',
        'start_percentage',
        'end_percentage',
        'usage_minutes',
        'notes',
    ];

    protected $casts = [
        'start_percentage' => 'decimal:2',
        'end_percentage' => 'decimal:2',
        'usage_minutes' => 'decimal:2',
    ];

    public function flight(): BelongsTo
    {
        return $this->belongsTo(
            FlightSession::class,
            'flight_session_id',
            'flight_session_id'
        );
    }

    public function battery(): BelongsTo
    {
        return $this->belongsTo(
            Battery::class,
            'battery_id',
            'battery_id'
        );
    }
}
