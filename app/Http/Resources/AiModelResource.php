<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiModelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'model_id' => $this->model_id,
            'model_name' => $this->model_name,
            'model_type' => $this->model_type,
            'framework' => $this->framework,
            'description' => $this->description,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
