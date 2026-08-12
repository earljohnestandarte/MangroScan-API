<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'report_id' => $this->report_id,
            'mission_id' => $this->mission_id,
            'site_id' => $this->site_id,
            'report_title' => $this->report_title,
            'report_type' => $this->report_type,
            'report_status' => $this->report_status,
            'generated_by' => $this->generated_by,
            'approved_by' => $this->approved_by,
            'summary' => $this->summary,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
