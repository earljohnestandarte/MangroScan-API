<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ValidationSession extends Model
{
    use HasUuids;

    protected $primaryKey = 'validation_session_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'mission_id',
        'site_id',
        'plot_id',
        'validated_by',
        'validation_date',
        'method',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['validation_date' => 'date'];
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(SurveyMission::class, 'mission_id', 'mission_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SurveySite::class, 'site_id', 'site_id');
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(MonitoringPlot::class, 'plot_id', 'plot_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by', 'user_id');
    }

    public function groundTruthRecords(): HasMany
    {
        return $this->hasMany(GroundTruthTreeRecord::class, 'validation_session_id', 'validation_session_id');
    }
}
