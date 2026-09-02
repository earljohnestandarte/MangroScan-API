<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BatteryUsageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'battery_usage_id' => $this->battery_usage_id,
            'flight_session_id' => $this->flight_session_id,
            'battery_id' => $this->battery_id,
            'start_percentage' => $this->start_percentage,
            'end_percentage' => $this->end_percentage,
            'usage_minutes' => $this->usage_minutes,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
