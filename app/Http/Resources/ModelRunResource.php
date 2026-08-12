<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ModelRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'model_run_id' => $this->model_run_id,
            'processing_job_id' => $this->processing_job_id,
            'model_version_id' => $this->model_version_id,
            'run_type' => $this->run_type,
            'input_media_id' => $this->input_media_id,
            'parameters' => $this->parameters,
            'started_at' => $this->started_at?->utc()->toIso8601String(),
            'completed_at' => $this->completed_at?->utc()->toIso8601String(),
            'run_status' => $this->run_status,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
        ];
    }
}
