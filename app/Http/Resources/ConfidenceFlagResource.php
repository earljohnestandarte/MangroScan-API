<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfidenceFlagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'confidence_flag_id' => $this->confidence_flag_id,
            'mission_id' => $this->mission_id,
            'result_id' => $this->result_id,
            'result_type' => $this->result_type,
            'status' => $this->status,
            'severity' => $this->severity,
            'review_note' => $this->review_note,
            'assigned_to' => $this->assigned_to,
            'reason' => $this->reason,
            'resolution_notes' => $this->resolution_notes,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
