<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiModelVersion extends Model
{
    use HasUuids;

    protected $primaryKey = 'model_version_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'model_id',
        'version_label',
        'model_file_path',
        'training_dataset_id',
        'accuracy',
        'precision_score',
        'recall_score',
        'f1_score',
        'rmse',
        'is_deployed',
        'release_notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'accuracy' => 'decimal:4',
            'precision_score' => 'decimal:4',
            'recall_score' => 'decimal:4',
            'f1_score' => 'decimal:4',
            'rmse' => 'decimal:4',
            'is_deployed' => 'boolean',
        ];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id', 'model_id');
    }

    public function accuracyMetrics(): HasMany
    {
        return $this->hasMany(AccuracyMetric::class, 'model_version_id', 'model_version_id');
    }
}
