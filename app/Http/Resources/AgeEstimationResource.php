<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgeEstimationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'age_estimation_id' => $this->age_estimation_id,
            'tree_observation_id' => $this->tree_observation_id,
            'growth_model_id' => $this->growth_model_id,
            'height_estimation_id' => $this->height_estimation_id,
            'estimated_age_years' => $this->estimated_age_years,
            'min_estimated_age_years' => $this->min_estimated_age_years,
            'max_estimated_age_years' => $this->max_estimated_age_years,
            'confidence_score' => $this->confidence_score,
            'assumptions' => $this->assumptions,
            'is_final' => $this->is_final,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
        ];
    }
}
