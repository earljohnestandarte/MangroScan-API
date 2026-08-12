<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DroneSensorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'sensor_id' => $this->sensor_id,
            'drone_id' => $this->drone_id,
            'sensor_name' => $this->sensor_name,
            'sensor_type' => $this->sensor_type,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'resolution' => $this->resolution,
            'range_meters' => $this->range_meters,
            'calibration_required' => (bool) $this->calibration_required,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
