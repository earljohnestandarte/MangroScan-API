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
            'job_type' => $this->job_type,
            'job_status' => $this->job_status,
            'input_summary' => $this->input_summary,
            'output_summary' => $this->output_summary,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'error_message' => $this->error_message,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
