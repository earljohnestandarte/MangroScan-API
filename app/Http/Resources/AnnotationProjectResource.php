<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnotationProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'annotation_project_id' => $this->annotation_project_id,
            'name' => $this->name,
            'dataset_type' => $this->dataset_type,
            'mission_id' => $this->mission_id,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
