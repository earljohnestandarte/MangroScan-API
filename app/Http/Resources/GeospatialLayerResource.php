<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GeospatialLayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'layer_id' => $this->layer_id,
            'mission_id' => $this->mission_id,
            'layer_name' => $this->layer_name,
            'layer_type' => $this->layer_type,
            'style_config' => $this->style_config,
            'is_visible_default' => $this->is_visible_default,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
