<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExportedFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'export_file_id' => $this->export_file_id,
            'report_id' => $this->report_id,
            'mission_id' => $this->mission_id,
            'export_type' => $this->export_type,
            'file_name' => $this->file_name,
            'file_size_bytes' => $this->file_size_bytes,
            'exported_by' => $this->exported_by,
            'exported_at' => $this->exported_at?->utc()->toIso8601String(),
        ];
    }
}
