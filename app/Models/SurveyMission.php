<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SurveyMission extends Model
{
    use HasUuids, SoftDeletes;

    protected $primaryKey = 'mission_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'site_id',
        'mission_code',
        'mission_title',
        'mission_objective',
        'planned_start_at',
        'planned_end_at',
        'actual_start_at',
        'actual_end_at',
        'mission_status',
        'coverage_target_hectares',
        'coverage_completed_hectares',
        'created_by',
        'approved_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'coverage_target_hectares' => 'decimal:4',
            'coverage_completed_hectares' => 'decimal:4',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SurveySite::class, 'site_id', 'site_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(MissionTeamMember::class, 'mission_id', 'mission_id');
    }
}
