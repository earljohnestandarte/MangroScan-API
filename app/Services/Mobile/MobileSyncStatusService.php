<?php

namespace App\Services\Mobile;

use App\Models\SyncDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class MobileSyncStatusService
{
    /**
     * @return array{last_cursor: ?string, last_sync_at: ?string, pending_notifications: array<int, mixed>}
     */
    public function status(User $actor, string $deviceId): array
    {
        $device = SyncDevice::query()
            ->where('device_id', $deviceId)
            ->whereHas('user', fn ($query) => $query->where('organization_id', $actor->organization_id))
            ->first();

        if ($device === null) {
            throw (new ModelNotFoundException())->setModel(SyncDevice::class, [$deviceId]);
        }

        return [
            'last_cursor' => $device->last_cursor,
            'last_sync_at' => $device->last_sync_at?->toIso8601String(),
            'pending_notifications' => [],
        ];
    }
}
