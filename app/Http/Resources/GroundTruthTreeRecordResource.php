<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroundTruthTreeRecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'ground_truth_id' => $this->ground_truth_id,
            'validation_session_id' => $this->validation_session_id,
            'field_code' => $this->field_code,
            'species_id' => $this->species_id,
            'ground_location' => $this->geometry($this->ground_location_geojson ?? $this->ground_location),
            'measured_height_meters' => $this->measured_height_meters,
            'estimated_age_years' => $this->estimated_age_years,
            'diameter_cm' => $this->diameter_cm,
            'crown_diameter_m' => $this->crown_diameter_m,
            'health_status' => $this->health_status,
            'is_tree' => $this->is_tree,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
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
