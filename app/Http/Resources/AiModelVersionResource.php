<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiModelVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'model_version_id' => $this->model_version_id,
            'model_id' => $this->model_id,
            'version_label' => $this->version_label,
            'training_dataset_id' => $this->training_dataset_id,
            'accuracy' => $this->accuracy,
            'precision_score' => $this->precision_score,
            'recall_score' => $this->recall_score,
            'f1_score' => $this->f1_score,
            'rmse' => $this->rmse,
            'is_deployed' => $this->is_deployed,
            'release_notes' => $this->release_notes,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
