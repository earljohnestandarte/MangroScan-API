<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpeciesClassificationResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'classification_result_id' => $this->classification_result_id,
            'tree_observation_id' => $this->tree_observation_id,
            'model_run_id' => $this->model_run_id,
            'predicted_species_id' => $this->predicted_species_id,
            'confidence_score' => $this->confidence_score,
            'rank_no' => $this->rank_no,
            'classification_basis' => $this->classification_basis,
            'is_final' => $this->is_final,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
        ];
    }
}
