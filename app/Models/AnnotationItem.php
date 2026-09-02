<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnnotationItem extends Model
{
    use HasUuids;

    protected $primaryKey = 'annotation_item_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['annotation_project_id', 'dataset_item_id', 'media_asset_id', 'status'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(AnnotationProject::class, 'annotation_project_id', 'annotation_project_id');
    }

    public function objects(): HasMany
    {
        return $this->hasMany(AnnotationObject::class, 'annotation_item_id', 'annotation_item_id');
    }
}
