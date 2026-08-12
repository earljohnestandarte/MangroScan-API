<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Drone extends Model
{
    use HasUuids, SoftDeletes;

    protected $primaryKey = 'drone_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'drone_name',
        'model',
        'serial_number',
        'firmware_version',
        'max_flight_minutes',
        'payload_capacity_grams',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'max_flight_minutes' => 'decimal:2',
            'payload_capacity_grams' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'organization_id');
    }

    public function flightSessions(): HasMany
    {
        return $this->hasMany(FlightSession::class, 'drone_id', 'drone_id');
    }

    public function sensors(): HasMany
    {
        return $this->hasMany(DroneSensor::class, 'drone_id', 'drone_id');
    }
}
