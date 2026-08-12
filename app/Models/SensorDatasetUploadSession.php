<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SensorDatasetUploadSession extends Model
{
    use HasUuids;

    protected $primaryKey = 'upload_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['file_size_bytes' => 'integer', 'metadata' => 'array', 'expires_at' => 'datetime'];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(FlightSession::class, 'flight_session_id', 'flight_session_id');
    }
}
