<?php

namespace App\Services\Mobile;

use App\Exceptions\WorkflowConflictException;
use App\Models\SyncDevice;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SyncDeviceRegistrationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{device: SyncDevice, server_time: CarbonImmutable}
     */
    public function register(
        User $actor,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): array {
        return DB::transaction(function () use (
            $actor,
            $data,
            $ipAddress,
            $userAgent,
            $requestId,
        ): array {
            $serverTime = CarbonImmutable::now('UTC');
            $existing = SyncDevice::query()
                ->lockForUpdate()
                ->find($data['device_id']);
            $old = $existing?->only(['user_id', 'platform', 'app_version', 'device_name']);

            DB::table('sync_devices')->insertOrIgnore([
                'device_id' => $data['device_id'],
                'user_id' => $actor->user_id,
                'platform' => $data['platform'],
                'app_version' => $data['app_version'],
                'device_name' => $data['device_name'] ?? null,
                'created_at' => $serverTime,
                'updated_at' => $serverTime,
            ]);

            $device = SyncDevice::query()
                ->lockForUpdate()
                ->findOrFail($data['device_id']);

            if ($device->user_id !== $actor->user_id) {
                throw new WorkflowConflictException(
                    'The device identifier is already registered to another account.',
                    ['device_id' => $data['device_id']],
                );
            }

            $new = [
                'user_id' => $actor->user_id,
                'platform' => $data['platform'],
                'app_version' => $data['app_version'],
                'device_name' => $data['device_name'] ?? null,
            ];

            if ($old !== $new) {
                $device->fill($new);
                $device->updated_at = $serverTime;
                $device->save();

                $this->auditLogger->record(
                    action: 'sync.device.register',
                    tableName: 'sync_devices',
                    recordId: $device->device_id,
                    userId: $actor->user_id,
                    oldValues: $old,
                    newValues: $new,
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                    requestId: $requestId,
                );
            }

            return [
                'device' => $device->refresh(),
                'server_time' => $serverTime,
            ];
        });
    }
}
