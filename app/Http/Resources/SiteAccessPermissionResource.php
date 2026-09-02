<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteAccessPermissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'access_permission_id' => $this->access_permission_id,
            'site_id' => $this->site_id,
            'permit_title' => $this->permit_title,
            'issuing_agency' => $this->issuing_agency,
            'permit_number' => $this->permit_number,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'document_path' => $this->document_path,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
