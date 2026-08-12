<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CanopyHeightEstimationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'height_estimation_id' => $this->height_estimation_id,
            'tree_observation_id' => $this->tree_observation_id,
            'model_run_id' => $this->model_run_id,
            'method' => $this->method,
            'height_meters' => $this->height_meters,
            'height_confidence_score' => $this->height_confidence_score,
            'source_dataset_id' => $this->source_dataset_id,
            'measurement_notes' => $this->measurement_notes,
            'is_final' => $this->is_final,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
        ];
    }
}
