<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonitoringPlotResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $geometry = $this->plot_geojson ?? $this->plot_geom;

        if (is_string($geometry)) {
            $geometry = json_decode($geometry, true, flags: JSON_THROW_ON_ERROR);
        }

        return [
            'plot_id' => $this->plot_id,
            'site_id' => $this->site_id,
            'plot_code' => $this->plot_code,
            'plot_name' => $this->plot_name,
            'plot_geom' => $geometry,
            'area_square_meters' => $this->area_square_meters,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
