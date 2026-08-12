<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveySiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $centerPoint = $this->center_point_geojson ?? $this->center_point;

        if (is_string($centerPoint)) {
            $centerPoint = json_decode($centerPoint, true, flags: JSON_THROW_ON_ERROR);
        }

        return [
            'site_id' => $this->site_id,
            'organization_id' => $this->organization_id,
            'site_name' => $this->site_name,
            'site_code' => $this->site_code,
            'description' => $this->description,
            'province' => $this->province,
            'city_municipality' => $this->city_municipality,
            'barangay' => $this->barangay,
            'center_point' => $centerPoint,
            'area_hectares' => $this->area_hectares,
            'environment_type' => $this->environment_type,
            'access_notes' => $this->access_notes,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
