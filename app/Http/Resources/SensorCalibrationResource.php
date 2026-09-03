<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SensorCalibrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'calibration_id' => $this->calibration_id,
            'sensor_id' => $this->sensor_id,
            'calibration_date' => $this->calibration_date?->toDateString(),
            'calibration_method' => $this->calibration_method,
            'calibration_file_path' => $this->when(! $this->relationLoaded('sensor'), $this->calibration_file_path),
            'has_calibration_file' => $this->whenLoaded('sensor', fn (): bool => filled($this->calibration_file_path)),
            'calibration_notes' => $this->calibration_notes,
            'is_valid' => (bool) $this->is_valid,
            'sensor' => $this->whenLoaded('sensor', function (): array {
                return [
                    'sensor_id' => $this->sensor->sensor_id,
                    'sensor_name' => $this->sensor->sensor_name,
                    'sensor_type' => $this->sensor->sensor_type,
                    'calibration_required' => (bool) $this->sensor->calibration_required,
                    'drone' => $this->sensor->relationLoaded('drone') && $this->sensor->drone
                        ? [
                            'drone_id' => $this->sensor->drone->drone_id,
                            'drone_name' => $this->sensor->drone->drone_name,
                        ]
                        : null,
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
