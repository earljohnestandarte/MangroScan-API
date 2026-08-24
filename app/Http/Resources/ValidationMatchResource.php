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
            'validation_session_id' => $this->validation_session_id,
            'ground_truth_id' => $this->ground_truth_id,
            'tree_observation_id' => $this->tree_observation_id,
            'match_status' => $this->match_status,
            'accepted_species_id' => $this->accepted_species_id,
            'accepted_height_m' => $this->accepted_height_m,
            'accepted_age_years' => $this->accepted_age_years,
            'corrected_geometry' => $this->geometry($this->corrected_geometry_geojson ?? $this->corrected_geometry),
            'notes' => $this->notes,
            'validation_evidence' => $this->validation_evidence,
            'distance_error_meters' => $this->distance_error_meters,
            'species_correct' => $this->species_correct,
            'height_error_meters' => $this->height_error_meters,
            'age_error_years' => $this->age_error_years,
            'validated_by' => $this->validated_by,
            'validated_at' => $this->validated_at?->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function geometry(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }

        return is_array($value) ? $value : null;
    }
}
