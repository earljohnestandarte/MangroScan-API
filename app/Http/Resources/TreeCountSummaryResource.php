<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TreeCountSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'tree_count_summary_id' => $this['tree_count_summary_id'],
            'mission_id' => $this['mission_id'],
            'site_id' => $this['site_id'],
            'species_id' => $this['species_id'],
            'model_run_id' => $this['model_run_id'],
            'total_detected_trees' => $this['total_detected_trees'],
            'validated_tree_count' => $this['validated_tree_count'],
            'estimated_density_per_hectare' => $this['estimated_density_per_hectare'],
            'count_confidence_score' => $this['count_confidence_score'],
            'created_at' => $this['created_at'],
            'updated_at' => $this['updated_at'],
        ];
    }
}
