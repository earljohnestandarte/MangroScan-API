<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcessingJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'processing_job_id' => $this->processing_job_id,
            'mission_id' => $this->mission_id,
            'flight_session_id' => $this->flight_session_id,
            'requested_by_user_id' => $this->requested_by_user_id,
            'job_type' => $this->job_type,
            'job_status' => $this->job_status,
            'parameters' => $this->parameters,
            'progress_percent' => $this->progress_percent,
            'attempt_count' => $this->attempt_count,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'output_summary' => $this->output_summary,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
