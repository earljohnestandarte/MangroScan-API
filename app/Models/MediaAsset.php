<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class MediaAsset extends Model
{
    use HasUuids, SoftDeletes;

    protected $primaryKey = 'media_asset_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'flight_session_id',
        'uploaded_by_user_id',
        'file_name',
        'file_type',
        'mime_type',
        'file_size_bytes',
        'storage_key',
        'checksum_sha256',
        'capture_location',
        'captured_at',
        'metadata',
        'quality_score',
        'quality_status',
        'notes',
        'processing_status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'captured_at' => 'datetime',
            'metadata' => 'array',
            'quality_score' => 'decimal:2',
            'sync_version' => 'integer',
        ];
    }

    public function scopeWithCaptureLocationGeoJson(Builder $query): Builder
    {
        if (DB::getDriverName() === 'pgsql') {
            $query
                ->select('media_assets.*')
                ->selectRaw('ST_AsGeoJSON(capture_location)::json AS capture_location_geojson');
        }

        return $query;
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(FlightSession::class, 'flight_session_id', 'flight_session_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id', 'user_id');
    }
}
