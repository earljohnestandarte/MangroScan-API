<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportDraftResource extends JsonResource
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
            'audience' => $this->audience,
            'summary' => $this->summary,
            'interpretation' => $this->interpretation,
            'limitations' => $this->limitations,
            'recommendations' => $this->recommendations,
            'formats' => $this->formats,
            'generated_by' => $this->generated_by,
            'approved_by' => $this->approved_by,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
