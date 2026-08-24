<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BatteryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'battery_id' => $this->battery_id,
            'organization_id' => $this->organization_id,
            'battery_code' => $this->battery_code,
            'battery_type' => $this->battery_type,
            'capacity_mah' => $this->capacity_mah,
            'voltage' => $this->voltage,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}