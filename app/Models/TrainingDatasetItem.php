<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingDatasetItem extends Model
{
    use HasUuids;

    protected $primaryKey = 'dataset_item_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'training_dataset_id', 'media_asset_id', 'label_file_path', 'label_format',
        'species_id', 'annotation_status', 'created_by',
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(TrainingDataset::class, 'training_dataset_id', 'training_dataset_id');
    }
}
