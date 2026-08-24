<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ValidationMatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'validation_match_id' => $this->validation_match_id,
            'ground_truth_id' => $this->ground_truth_id,
            'tree_observation_id' => $this->tree_observation_id,
            'match_status' => $this->match_status,
            'distance_error_meters' => $this->distance_error_meters,
            'species_correct' => $this->species_correct,
            'height_error_meters' => $this->height_error_meters,
            'age_error_years' => $this->age_error_years,
            'validated_by' => $this->validated_by,
            'validated_at' => $this->validated_at?->utc()->toIso8601String(),
        ];
    }
}
