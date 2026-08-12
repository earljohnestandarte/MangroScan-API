<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiModel extends Model
{
    use HasUuids, SoftDeletes;

    protected $primaryKey = 'model_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'model_name',
        'model_type',
        'framework',
        'description',
        'ai_service_id',
        'external_model_key',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(AiService::class, 'ai_service_id', 'ai_service_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AiModelVersion::class, 'model_id', 'model_id');
    }
}
