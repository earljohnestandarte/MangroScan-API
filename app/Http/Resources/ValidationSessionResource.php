<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ValidationSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'validation_session_id' => $this->validation_session_id,
            'mission_id' => $this->mission_id,
            'site_id' => $this->site_id,
            'plot_id' => $this->plot_id,
            'validated_by' => $this->validated_by,
            'validation_date' => $this->validation_date?->format('Y-m-d'),
            'method' => $this->method,
            'status' => $this->status,
            'notes' => $this->notes,
            'completed_at' => $this->completed_at?->utc()->toIso8601String(),
            'completed_by' => $this->completed_by,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
