<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DroneResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'drone_id' => $this->drone_id,
            'organization_id' => $this->organization_id,
            'drone_name' => $this->drone_name,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'firmware_version' => $this->firmware_version,
            'max_flight_minutes' => $this->max_flight_minutes,
            'payload_capacity_grams' => $this->payload_capacity_grams,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
