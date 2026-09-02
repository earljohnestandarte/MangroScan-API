<?php

namespace App\Services\Mobile;

use App\Models\SyncChange;
use App\Models\SyncDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileSyncService
{
    public function __construct(
        private readonly SyncCursorService $cursors,
    ) {}

    public function sync(
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
            $device = SyncDevice::query()
                ->where('device_id', $data['device_id'])
                ->where('user_id', $actor->user_id)
                ->whereHas('user', fn ($q) => $q->where('organization_id', $actor->organization_id))
                ->lockForUpdate()
                ->first();

            if (! $device) {
                throw ValidationException::withMessages([
                    'device_id' => ['The device is not registered to the authenticated user.'],
                ]);
            }

            $this->cursors->decode($data['base_cursor']);

            $applied = [];
            $conflicts = [];

            foreach ($data['changes'] as $change) {
                $existing = SyncChange::query()
                    ->where('device_id', $device->device_id)
                    ->where('client_id', $change['client_id'])
                    ->first();

                if ($existing) {
                    $result = $existing->result_payload ?? [];

                    if ($existing->payload_hash === hash('sha256', json_encode($change['payload'], JSON_THROW_ON_ERROR))) {
                        if ($existing->result_status === 'applied') {
                            $applied[] = $result;
                        } else {
                            $conflicts[] = $result;
                        }

                        continue;
                    }

                    throw ValidationException::withMessages([
                        'changes' => ["Duplicate client_id '{$change['client_id']}' has a different payload."],
                    ]);
                }

                $payloadHash = hash(
                    'sha256',
                    json_encode($change['payload'], JSON_THROW_ON_ERROR)
                );

                $result = [
                    'client_id' => $change['client_id'],
                    'entity' => $change['entity'],
                    'operation' => $change['operation'],
                    'status' => 'conflict',
                    'message' => 'Offline mutation requires resource-specific reconciliation before it can be applied.',
                ];

                $record = SyncChange::query()->create([
                    'device_id' => $device->device_id,
                    'client_id' => $change['client_id'],
                    'entity_type' => $change['entity'],
                    'operation' => $change['operation'],
                    'client_version' => $change['version'],
                    'payload_hash' => $payloadHash,
                    'result_status' => 'conflict',
                    'server_id' => null,
                    'server_version' => null,
                    'result_payload' => $result,
                    'created_at' => now(),
                ]);

                DB::table('sync_conflicts')->insert([
                    'sync_conflict_id' => (string) Str::uuid(),
                    'sync_change_id' => $record->sync_change_id,
                    'device_id' => $device->device_id,
                    'client_id' => $change['client_id'],
                    'entity_type' => $change['entity'],
                    'conflict_code' => 'RESOURCE_RECONCILIATION_REQUIRED',
                    'client_payload' => json_encode($change['payload'], JSON_THROW_ON_ERROR),
                    'server_payload' => null,
                    'message' => $result['message'],
                    'created_at' => now(),
                ]);

                $conflicts[] = $result;
            }

            $serverTime = now()->toImmutable()->utc();
            $device->forceFill([
                'last_cursor' => $this->cursors->encode($serverTime),
                'last_sync_at' => $serverTime,
            ])->save();

            return [
                'applied' => $applied,
                'conflicts' => $conflicts,
                'server_changes' => [],
                'cursor' => $device->last_cursor,
            ];
        });
    }
}
