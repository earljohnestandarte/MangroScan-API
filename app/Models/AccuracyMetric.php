<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccuracyMetric extends Model
{
    use HasUuids;

    protected $primaryKey = 'accuracy_metric_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'validation_session_id',
        'mission_id',
        'model_version_id',
        'metric_type',
        'metric_value',
        'sample_size',
        'computed_at',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metric_value' => 'decimal:6',
            'sample_size' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(SurveyMission::class, 'mission_id', 'mission_id');
    }

    public function validationSession(): BelongsTo
    {
        return $this->belongsTo(ValidationSession::class, 'validation_session_id', 'validation_session_id');
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(AiModelVersion::class, 'model_version_id', 'model_version_id');
    }
}
