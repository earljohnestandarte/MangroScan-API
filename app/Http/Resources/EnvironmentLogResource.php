<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnvironmentLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'environment_log_id' => $this->environment_log_id,
            'flight_session_id' => $this->flight_session_id,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'weather_condition' => $this->weather_condition,
            'wind_speed_mps' => $this->wind_speed_mps,
            'temperature_celsius' => $this->temperature_celsius,
            'humidity_percent' => $this->humidity_percent,
            'visibility_status' => $this->visibility_status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
