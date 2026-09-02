<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnotationObject extends Model
{
    use HasUuids;

    protected $primaryKey = 'annotation_object_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['annotation_item_id', 'class_id', 'bbox', 'polygon', 'attributes', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['bbox' => 'array', 'polygon' => 'array', 'attributes' => 'array', 'created_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AnnotationItem::class, 'annotation_item_id', 'annotation_item_id');
    }
}
