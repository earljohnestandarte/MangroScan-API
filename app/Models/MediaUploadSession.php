<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaUploadSession extends Model
{
    use HasUuids;

    protected $primaryKey = 'upload_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'flight_session_id',
        'initiated_by_user_id',
        'idempotency_key',
        'request_fingerprint',
        'storage_disk',
        'storage_key',
        'file_name',
        'file_type',
        'mime_type',
        'file_size_bytes',
        'checksum_sha256',
        'capture_location',
        'captured_at',
        'metadata',
        'upload_status',
        'expires_at',
        'completed_at',
        'media_asset_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'captured_at' => 'datetime',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(FlightSession::class, 'flight_session_id', 'flight_session_id');
    }
}
