<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiService extends Model
{
    use HasUuids;

    protected $primaryKey = 'ai_service_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'service_name',
        'base_url',
        'encrypted_api_key',
        'environment',
        'enabled',
        'health_status',
        'service_version',
        'capabilities',
        'last_health_checked_at',
        'last_health_latency_ms',
        'last_synchronized_at',
        'created_by',
    ];

    protected $hidden = ['encrypted_api_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'capabilities' => 'array',
            'last_health_checked_at' => 'datetime',
            'last_health_latency_ms' => 'integer',
            'last_synchronized_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
