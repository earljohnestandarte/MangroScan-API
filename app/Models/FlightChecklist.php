<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightChecklist extends Model
{
    use HasUuids;

    protected $primaryKey = 'checklist_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'flight_session_id',
        'checked_by',
        'checklist_type',
        'battery_ok',
        'weather_ok',
        'gps_ok',
        'camera_ok',
        'lidar_depth_ok',
        'storage_ok',
        'overall_status',
        'remarks',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'battery_ok' => 'boolean',
            'weather_ok' => 'boolean',
            'gps_ok' => 'boolean',
            'camera_ok' => 'boolean',
            'lidar_depth_ok' => 'boolean',
            'storage_ok' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(FlightSession::class, 'flight_session_id', 'flight_session_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by', 'user_id');
    }
}
