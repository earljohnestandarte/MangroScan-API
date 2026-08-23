<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessingJob extends Model
{
    use HasUuids;

    protected $primaryKey = 'processing_job_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'mission_id',
        'flight_session_id',
        'job_type',
        'job_status',
        'input_summary',
        'output_summary',
        'started_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'error_message',
        'created_by',
        'idempotency_key',
        'request_fingerprint',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'input_summary' => 'array',
            'output_summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function modelRuns(): HasMany
    {
        return $this->hasMany(ModelRun::class, 'processing_job_id', 'processing_job_id');
    }
}
