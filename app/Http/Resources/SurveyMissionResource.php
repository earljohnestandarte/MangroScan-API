<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveyMissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'mission_id' => $this->mission_id,
            'site_id' => $this->site_id,
            'mission_code' => $this->mission_code,
            'mission_title' => $this->mission_title,
            'mission_objective' => $this->mission_objective,
            'planned_start_at' => $this->planned_start_at?->utc()->toIso8601String(),
            'planned_end_at' => $this->planned_end_at?->utc()->toIso8601String(),
            'actual_start_at' => $this->actual_start_at?->utc()->toIso8601String(),
            'actual_end_at' => $this->actual_end_at?->utc()->toIso8601String(),
            'status' => $this->mission_status,
            'coverage_target_hectares' => $this->coverage_target_hectares,
            'coverage_completed_hectares' => $this->coverage_completed_hectares,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
