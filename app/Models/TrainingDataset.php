<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingDataset extends Model
{
    use HasUuids;

    protected $primaryKey = 'training_dataset_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['dataset_name', 'dataset_type', 'source', 'description', 'version_label', 'created_by'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TrainingDatasetItem::class, 'training_dataset_id', 'training_dataset_id');
    }
}
