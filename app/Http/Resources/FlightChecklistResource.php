<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlightChecklistResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'checklist_id' => $this->checklist_id,
            'flight_session_id' => $this->flight_session_id,
            'checked_by' => $this->checked_by,
            'checklist_type' => $this->checklist_type,
            'battery_ok' => $this->battery_ok,
            'weather_ok' => $this->weather_ok,
            'gps_ok' => $this->gps_ok,
            'camera_ok' => $this->camera_ok,
            'lidar_depth_ok' => $this->lidar_depth_ok,
            'storage_ok' => $this->storage_ok,
            'overall_status' => $this->overall_status,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
