<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingDatasetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'training_dataset_id' => $this->training_dataset_id,
            'dataset_name' => $this->dataset_name,
            'dataset_type' => $this->dataset_type,
            'source' => $this->source,
            'description' => $this->description,
            'version_label' => $this->version_label,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
