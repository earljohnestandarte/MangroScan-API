<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingDatasetItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'dataset_item_id' => $this->dataset_item_id,
            'training_dataset_id' => $this->training_dataset_id,
            'media_id' => $this->media_asset_id,
            'label_file_path' => $this->label_file_path,
            'label_format' => $this->label_format,
            'species_id' => $this->species_id,
            'annotation_status' => $this->annotation_status,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
