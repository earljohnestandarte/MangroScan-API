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
            'calibration_file_path' => $this->calibration_file_path,
            'calibration_notes' => $this->calibration_notes,
            'is_valid' => (bool) $this->is_valid,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
