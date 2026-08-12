<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncChange extends Model
{
    use HasUuids;

    protected $table = 'sync_change_log';

    protected $primaryKey = 'sync_change_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'client_id',
        'entity_type',
        'operation',
        'client_version',
        'payload_hash',
        'result_status',
        'server_id',
        'server_version',
        'result_payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'client_version' => 'integer',
            'server_version' => 'integer',
            'result_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(SyncDevice::class, 'device_id', 'device_id');
    }
}
