<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccuracyMetricResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'accuracy_metric_id' => $this->accuracy_metric_id,
            'validation_session_id' => $this->validation_session_id,
            'mission_id' => $this->mission_id,
            'model_version_id' => $this->model_version_id,
            'metric_type' => $this->metric_type,
            'metric_value' => $this->metric_value,
            'sample_size' => $this->sample_size,
            'computed_at' => $this->computed_at?->utc()->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
