<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SensorDatasetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'sensor_dataset_id' => $this->sensor_dataset_id,
            'flight_session_id' => $this->flight_session_id,
            'sensor_id' => $this->sensor_id,
            'dataset_type' => $this->dataset_type,
            'file_name' => $this->file_name,
            'file_format' => $this->file_format,
            'recorded_start_at' => $this->recorded_start_at?->utc()->toIso8601String(),
            'recorded_end_at' => $this->recorded_end_at?->utc()->toIso8601String(),
            'spatial_reference' => $this->spatial_reference,
            'metadata' => $this->metadata,
            'quality_status' => $this->quality_status,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
