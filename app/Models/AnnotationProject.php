<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnnotationProject extends Model
{
    use HasUuids;

    protected $primaryKey = 'annotation_project_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['organization_id', 'name', 'dataset_type', 'mission_id', 'status', 'created_by'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'organization_id');
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(SurveyMission::class, 'mission_id', 'mission_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AnnotationItem::class, 'annotation_project_id', 'annotation_project_id');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(AnnotationExport::class, 'annotation_project_id', 'annotation_project_id');
    }
}
