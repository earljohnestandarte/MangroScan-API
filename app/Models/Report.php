<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use HasUuids;

    protected $primaryKey = 'report_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'mission_id',
        'site_id',
        'report_title',
        'report_type',
        'report_status',
        'generated_by',
        'approved_by',
        'summary',
        'audience',
        'interpretation',
        'limitations',
        'recommendations',
        'formats',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['formats' => 'array'];
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(SurveyMission::class, 'mission_id', 'mission_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SurveySite::class, 'site_id', 'site_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by', 'user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }
}
