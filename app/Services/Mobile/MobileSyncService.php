<?php

namespace App\Services\Mobile;

use App\Exceptions\WorkflowConflictException;
use App\Models\SyncChange;
use App\Models\SyncDevice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileSyncService
{
    public function __construct(
        private readonly SyncCursorService $cursors,
        private readonly MobileSyncMutationService $mutations,
        private readonly MobileSyncChangeFeedService $feed,
    ) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
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
                ->whereHas('user', fn ($query) => $query
                    ->where('organization_id', $actor->organization_id))
                ->lockForUpdate()
                ->first();

            if (! $device instanceof SyncDevice) {
                throw ValidationException::withMessages([
                    'device_id' => ['The device is not registered to the authenticated user.'],
                ]);
            }

            $after = $this->cursors->decode($data['base_cursor']);
            $requestHash = $this->hashValue([
                'base_cursor' => $data['base_cursor'],
                'changes' => $data['changes'],
            ]);
            $idempotencyKey = $this->batchIdempotencyKey($data);
            $existingRequest = DB::table('sync_requests')
                ->where('device_id', $device->device_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingRequest !== null) {
                if (! hash_equals($existingRequest->request_hash, $requestHash)) {
                    throw ValidationException::withMessages([
                        'changes' => ['A retried sync batch contains different change data.'],
                    ]);
                }

                if ($existingRequest->completed_at === null || $existingRequest->response_payload === null) {
                    throw ValidationException::withMessages([
                        'changes' => ['The matching sync batch has not completed. Retry later.'],
                    ]);
                }

                return $this->decodePayload($existingRequest->response_payload);
            }

            $syncRequestId = (string) Str::uuid();
            DB::table('sync_requests')->insert([
                'sync_request_id' => $syncRequestId,
                'device_id' => $device->device_id,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'created_at' => now(),
            ]);
            $applied = [];
            $conflicts = [];

            foreach ($data['changes'] as $change) {
                $hash = $this->changeHash($change);
                $existing = SyncChange::query()
                    ->where('device_id', $device->device_id)
                    ->where('client_id', $change['client_id'])
                    ->first();

                if ($existing instanceof SyncChange) {
                    if (! hash_equals($existing->payload_hash, $hash)) {
                        throw ValidationException::withMessages([
                            'changes' => ["Duplicate client_id '{$change['client_id']}' has different change data."],
                        ]);
                    }

                    if ($existing->result_status === 'applied') {
                        $applied[] = $existing->result_payload;
                    } else {
                        $conflicts[] = $existing->result_payload;
                    }

                    continue;
                }

                try {
                    $result = $this->mutations->apply(
                        $actor,
                        $change,
                        $ipAddress,
                        $userAgent,
                        $requestId,
                    );
                    $this->recordChange($device, $change, $hash, 'applied', $result);
                    $applied[] = $result;
                } catch (WorkflowConflictException $exception) {
                    $result = $this->conflictResult(
                        $change,
                        $exception->details['conflict_code'] ?? 'WORKFLOW_CONFLICT',
                        $exception->getMessage(),
                        $exception->details,
                    );
                    $record = $this->recordChange($device, $change, $hash, 'conflict', $result);
                    $this->recordConflict($record, $device, $change, $result, $exception->details['server_payload'] ?? null);
                    $conflicts[] = $result;
                } catch (ValidationException $exception) {
                    $result = $this->conflictResult(
                        $change,
                        'VALIDATION_FAILED',
                        'The mobile mutation payload is invalid.',
                        ['errors' => $exception->errors()],
                    );
                    $record = $this->recordChange($device, $change, $hash, 'conflict', $result);
                    $this->recordConflict($record, $device, $change, $result);
                    $conflicts[] = $result;
                } catch (ModelNotFoundException) {
                    $result = $this->conflictResult(
                        $change,
                        'RESOURCE_NOT_FOUND',
                        'The target resource is unavailable.',
                    );
                    $record = $this->recordChange($device, $change, $hash, 'conflict', $result);
                    $this->recordConflict($record, $device, $change, $result);
                    $conflicts[] = $result;
                }
            }

            $serverTime = CarbonImmutable::now('UTC');
            $serverChanges = $this->feed->changes($actor, $after, $serverTime);
            $cursor = $this->cursors->encode($serverTime);
            $device->forceFill([
                'last_cursor' => $cursor,
                'last_sync_at' => $serverTime,
            ])->save();

            $result = [
                'applied' => $applied,
                'conflicts' => $conflicts,
                'server_changes' => $serverChanges,
                'cursor' => $cursor,
            ];
            DB::table('sync_requests')->where('sync_request_id', $syncRequestId)->update([
                'response_payload' => json_encode($result, JSON_THROW_ON_ERROR),
                'completed_at' => now(),
            ]);

            return $result;
        });
    }

    /** @param array<string, mixed> $change */
    private function changeHash(array $change): string
    {
        return $this->hashValue([
            'entity' => $change['entity'],
            'operation' => $change['operation'],
            'version' => $change['version'],
            'payload' => $change['payload'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function batchIdempotencyKey(array $data): string
    {
        $clientIds = array_column($data['changes'], 'client_id');
        sort($clientIds, SORT_STRING);

        return 'batch:'.$this->hashValue([
            'base_cursor' => $data['base_cursor'],
            'client_ids' => $clientIds,
        ]);
    }

    private function hashValue(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    /** @return array<string, mixed> */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        return json_decode((string) $payload, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $change @param array<string, mixed> $result */
    private function recordChange(
        SyncDevice $device,
        array $change,
        string $hash,
        string $status,
        array $result,
    ): SyncChange {
        return SyncChange::query()->create([
            'device_id' => $device->device_id,
            'client_id' => $change['client_id'],
            'entity_type' => $change['entity'],
            'operation' => $change['operation'],
            'client_version' => $change['version'],
            'payload_hash' => $hash,
            'result_status' => $status,
            'server_id' => $result['server_id'] ?? null,
            'server_version' => $result['server_version'] ?? null,
            'result_payload' => $result,
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $change @param array<string, mixed> $details @return array<string, mixed> */
    private function conflictResult(array $change, string $code, string $message, array $details = []): array
    {
        return [
            'client_id' => $change['client_id'],
            'entity' => $change['entity'],
            'operation' => $change['operation'],
            'status' => 'conflict',
            'code' => $code,
            'message' => $message,
            'server_version' => $details['server_version'] ?? null,
            'server_payload' => $details['server_payload'] ?? null,
            'details' => array_diff_key($details, array_flip(['server_version', 'server_payload', 'conflict_code'])),
        ];
    }

    /** @param array<string, mixed> $change @param array<string, mixed> $result @param array<string, mixed>|null $serverPayload */
    private function recordConflict(
        SyncChange $record,
        SyncDevice $device,
        array $change,
        array $result,
        ?array $serverPayload = null,
    ): void {
        DB::table('sync_conflicts')->insert([
            'sync_conflict_id' => (string) Str::uuid(),
            'sync_change_id' => $record->sync_change_id,
            'device_id' => $device->device_id,
            'client_id' => $change['client_id'],
            'entity_type' => $change['entity'],
            'conflict_code' => $result['code'],
            'client_payload' => json_encode($change['payload'], JSON_THROW_ON_ERROR),
            'server_payload' => $serverPayload === null
                ? null
                : json_encode($serverPayload, JSON_THROW_ON_ERROR),
            'message' => $result['message'],
            'created_at' => now(),
        ]);
    }
}
