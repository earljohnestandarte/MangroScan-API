<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnotationExport extends Model
{
    use HasUuids;

    protected $primaryKey = 'annotation_export_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['annotation_project_id', 'format', 'file_name', 'storage_key', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AnnotationProject::class, 'annotation_project_id', 'annotation_project_id');
    }
}
