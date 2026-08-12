<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'notification_id' => $this->notification_id,
            'user_id' => $this->user_id,
            'notification_type' => $this->notification_type,
            'title' => $this->title,
            'message' => $this->message,
            'is_read' => $this->is_read,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
        ];
    }
}
