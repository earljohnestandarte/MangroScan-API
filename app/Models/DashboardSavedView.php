<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardSavedView extends Model
{
    use HasUuids;

    protected $primaryKey = 'saved_view_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'site_id', 'mission_id', 'view_name', 'filter_config', 'map_config'];

    protected function casts(): array
    {
        return ['filter_config' => 'array', 'map_config' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SurveySite::class, 'site_id', 'site_id');
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(SurveyMission::class, 'mission_id', 'mission_id');
    }
}
