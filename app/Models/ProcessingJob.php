<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingJob extends Model
{
    use HasUuids;

    protected $primaryKey = 'processing_job_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'mission_id',
        'flight_session_id',
        'requested_by_user_id',
        'job_type',
        'job_status',
        'parameters',
        'progress_percent',
        'attempt_count',
        'queued_at',
        'started_at',
        'completed_at',
        'error_code',
        'error_message',
        'output_summary',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'progress_percent' => 'integer',
            'attempt_count' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'output_summary' => 'array',
        ];
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(SurveyMission::class, 'mission_id', 'mission_id');
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(FlightSession::class, 'flight_session_id', 'flight_session_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id', 'user_id');
    }
}
