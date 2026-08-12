<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TreeObservationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'tree_observation_id' => $this->tree_observation_id,
            'tree_entity_id' => $this->tree_entity_id,
            'mission_id' => $this->mission_id,
            'flight_session_id' => $this->flight_session_id,
            'model_run_id' => $this->model_run_id,
            'source_media_id' => $this->source_media_id,
            'tree_code' => $this->tree_code,
            'tree_location' => $this->geometry($this->tree_location_geojson ?? $this->tree_location),
            'crown_polygon' => $this->geometry($this->crown_polygon_geojson ?? $this->crown_polygon),
            'bounding_box' => $this->bounding_box,
            'detection_confidence' => $this->detection_confidence,
            'final_species_id' => $this->final_species_id,
            'final_height_meters' => $this->final_height_meters,
            'final_estimated_age_years' => $this->final_estimated_age_years,
            'validation_status' => $this->validation_status,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
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
