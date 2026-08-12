<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteBoundaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $geometry = $this->boundary_geojson ?? $this->boundary_geom;

        if (is_string($geometry)) {
            $geometry = json_decode($geometry, true, flags: JSON_THROW_ON_ERROR);
        }

        return [
            'boundary_id' => $this->boundary_id,
            'site_id' => $this->site_id,
            'boundary_name' => $this->boundary_name,
            'boundary_type' => $this->boundary_type,
            'boundary_geom' => $geometry,
            'source' => $this->source,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
