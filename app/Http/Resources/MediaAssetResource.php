<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaAssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $captureLocation = $this->location(
            $this->capture_location_geojson ?? $this->capture_location,
        );

        return [
            'media_asset_id' => $this->media_asset_id,
            'flight_session_id' => $this->flight_session_id,
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            'file_name' => $this->file_name,
            'file_type' => $this->file_type,
            'mime_type' => $this->mime_type,
            'file_size_bytes' => $this->file_size_bytes,
            'checksum_sha256' => $this->checksum_sha256,
            'capture_location' => $captureLocation,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'metadata' => $this->metadata,
            'quality_score' => $this->quality_score,
            'quality_status' => $this->quality_status,
            'notes' => $this->notes,
            'processing_status' => $this->processing_status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function location(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }

        return is_array($value) ? $value : null;
    }
}
