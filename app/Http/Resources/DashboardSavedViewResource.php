<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardSavedViewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'saved_view_id' => $this->saved_view_id,
            'user_id' => $this->user_id,
            'site_id' => $this->site_id,
            'mission_id' => $this->mission_id,
            'view_name' => $this->view_name,
            'filter_config' => $this->filter_config,
            'map_config' => $this->map_config,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
