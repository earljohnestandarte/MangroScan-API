<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlightSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $takeoff = $this->location($this->takeoff_location_geojson ?? $this->takeoff_location);
        $landing = $this->location($this->landing_location_geojson ?? $this->landing_location);

        return [
            'flight_session_id' => $this->flight_session_id,
            'mission_id' => $this->mission_id,
            'drone_id' => $this->drone_id,
            'pilot_user_id' => $this->pilot_user_id,
            'flight_code' => $this->flight_code,
            'takeoff_location' => $takeoff,
            'landing_location' => $landing,
            'planned_altitude_meters' => $this->planned_altitude_meters,
            'actual_avg_altitude_meters' => $this->actual_avg_altitude_meters,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'flight_duration_minutes' => $this->flight_duration_minutes,
            'status' => $this->flight_status,
            'quality_status' => $this->quality_status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function location(mixed $value): ?array
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
