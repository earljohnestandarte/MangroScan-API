<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiServiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'ai_service_id' => $this->ai_service_id,
            'service_name' => $this->service_name,
            'base_url' => $this->base_url,
            'environment' => $this->environment,
            'enabled' => $this->enabled,
            'health_status' => $this->health_status,
            'service_version' => $this->service_version,
            'capabilities' => $this->capabilities,
            'last_health_checked_at' => $this->last_health_checked_at?->utc()->toIso8601String(),
            'last_synchronized_at' => $this->last_synchronized_at?->utc()->toIso8601String(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
