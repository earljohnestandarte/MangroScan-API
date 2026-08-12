<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelRun extends Model
{
    use HasUuids;

    protected $primaryKey = 'model_run_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'processing_job_id',
        'model_version_id',
        'run_type',
        'input_media_id',
        'parameters',
        'started_at',
        'completed_at',
        'run_status',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function processingJob(): BelongsTo
    {
        return $this->belongsTo(ProcessingJob::class, 'processing_job_id', 'processing_job_id');
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(AiModelVersion::class, 'model_version_id', 'model_version_id');
    }

    public function inputMedia(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'input_media_id', 'media_asset_id');
    }
}
