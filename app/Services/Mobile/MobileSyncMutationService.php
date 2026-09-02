<?php

namespace App\Services\Mobile;

use App\Exceptions\WorkflowConflictException;
use App\Http\Resources\FlightChecklistResource;
use App\Http\Resources\FlightSessionResource;
use App\Http\Resources\GroundTruthTreeRecordResource;
use App\Http\Resources\MediaAssetResource;
use App\Models\FlightSession;
use App\Models\User;
use App\Services\Auth\EffectiveAccessService;
use App\Services\Flight\FlightChecklistSubmissionService;
use App\Services\Flight\FlightCompletionService;
use App\Services\Flight\FlightFailureService;
use App\Services\Flight\FlightStartService;
use App\Services\Flight\ScopedFlightService;
use App\Services\Media\MediaQualityUpdateService;
use App\Services\Media\ScopedMediaAssetService;
use App\Services\Validation\GroundTruthCreationService;
use App\Services\Validation\ScopedValidationSessionService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MobileSyncMutationService
{
    public function __construct(
        private readonly EffectiveAccessService $access,
        private readonly ScopedFlightService $flights,
        private readonly FlightChecklistSubmissionService $checklists,
        private readonly FlightStartService $flightStart,
        private readonly FlightCompletionService $flightCompletion,
        private readonly FlightFailureService $flightFailure,
        private readonly ScopedMediaAssetService $media,
        private readonly MediaQualityUpdateService $mediaQuality,
        private readonly ScopedValidationSessionService $validationSessions,
        private readonly GroundTruthCreationService $groundTruth,
    ) {}

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    public function apply(
        User $actor,
        array $change,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): array {
        return match ($change['entity']) {
            'flight_checklist' => $this->checklist($actor, $change, $ipAddress, $userAgent, $requestId),
            'flight_session' => $this->flight($actor, $change, $ipAddress, $userAgent, $requestId),
            'media' => $this->media($actor, $change, $ipAddress, $userAgent, $requestId),
            'validation_record' => $this->validation($actor, $change, $ipAddress, $userAgent, $requestId),
            default => throw new WorkflowConflictException('The mobile entity is not supported.', [
                'conflict_code' => 'UNSUPPORTED_ENTITY',
            ]),
        };
    }

    /** @param array<string, mixed> $change @return array<string, mixed> */
    private function checklist(User $actor, array $change, ?string $ip, ?string $agent, ?string $requestId): array
    {
        $this->requireOperation($change, ['create']);
        $this->requirePermission($actor, 'checklists.submit');
        $payload = Validator::make($change['payload'], [
            'flight_id' => ['required', 'uuid'],
            'checklist_type' => ['required', 'string', Rule::in(['pre_flight', 'post_flight'])],
            'battery_ok' => ['required', 'boolean'],
            'weather_ok' => ['required', 'boolean'],
            'gps_ok' => ['required', 'boolean'],
            'camera_ok' => ['required', 'boolean'],
            'lidar_depth_ok' => ['required', 'boolean'],
            'storage_ok' => ['required', 'boolean'],
            'overall_status' => ['required', 'string', Rule::in(['passed', 'failed', 'conditional'])],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ])->validate();
        $flight = $this->flights->find($actor, $payload['flight_id']);
        $this->assertVersion($flight, (int) $change['version']);
        unset($payload['flight_id']);
        $checklist = $this->checklists->submit($actor, $flight, $payload, $ip, $agent, $requestId);
        $serverVersion = (int) FlightSession::query()->findOrFail($flight->flight_session_id)->sync_version;

        return $this->result(
            $change,
            $checklist->checklist_id,
            $serverVersion,
            (new FlightChecklistResource($checklist))->resolve(request()),
        );
    }

    /** @param array<string, mixed> $change @return array<string, mixed> */
    private function flight(User $actor, array $change, ?string $ip, ?string $agent, ?string $requestId): array
    {
        $this->requireOperation($change, ['update', 'upsert']);
        $payload = Validator::make($change['payload'], [
            'flight_id' => ['required', 'uuid'],
            'status' => ['required', 'string', Rule::in(['flying', 'completed', 'aborted', 'failed'])],
            'started_at' => ['required_if:status,flying', 'date'],
            'ended_at' => ['required_if:status,completed', 'nullable', 'date'],
            'takeoff_location' => ['sometimes', 'nullable', 'array'],
            'takeoff_location.type' => ['required_with:takeoff_location', 'in:Point'],
            'takeoff_location.coordinates' => ['required_with:takeoff_location', 'array', 'size:2'],
            'takeoff_location.coordinates.0' => ['required_with:takeoff_location', 'numeric', 'between:-180,180'],
            'takeoff_location.coordinates.1' => ['required_with:takeoff_location', 'numeric', 'between:-90,90'],
            'landing_location' => ['sometimes', 'nullable', 'array'],
            'landing_location.type' => ['required_with:landing_location', 'in:Point'],
            'landing_location.coordinates' => ['required_with:landing_location', 'array', 'size:2'],
            'landing_location.coordinates.0' => ['required_with:landing_location', 'numeric', 'between:-180,180'],
            'landing_location.coordinates.1' => ['required_with:landing_location', 'numeric', 'between:-90,90'],
            'actual_avg_altitude_meters' => ['sometimes', 'nullable', 'numeric', 'between:0,999999.99'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'reason' => ['required_if:status,aborted,failed', 'string', 'max:5000'],
        ])->validate();
        $flight = $this->flights->find($actor, $payload['flight_id']);
        $this->assertVersion($flight, (int) $change['version']);

        if ($payload['status'] === 'flying') {
            $this->requirePermission($actor, 'flights.start');
            $updated = $this->flightStart->start($actor, $flight, [
                'started_at' => $payload['started_at'],
                ...$this->onlyPresent($payload, ['takeoff_location']),
            ], $ip, $agent, $requestId);
        } elseif ($payload['status'] === 'completed') {
            $this->requirePermission($actor, 'flights.complete');
            $updated = $this->flightCompletion->complete($actor, $flight, [
                'ended_at' => $payload['ended_at'],
                ...$this->onlyPresent($payload, ['landing_location', 'actual_avg_altitude_meters', 'notes']),
            ], $ip, $agent, $requestId);
        } else {
            $this->requirePermission($actor, 'flights.complete');
            $updated = $this->flightFailure->fail($actor, $flight, [
                'status' => $payload['status'],
                'reason' => $payload['reason'],
                ...$this->onlyPresent($payload, ['ended_at']),
            ], $ip, $agent, $requestId);
        }

        return $this->result(
            $change,
            $updated->flight_session_id,
            (int) $updated->sync_version,
            (new FlightSessionResource($updated))->resolve(request()),
        );
    }

    /** @param array<string, mixed> $change @return array<string, mixed> */
    private function media(User $actor, array $change, ?string $ip, ?string $agent, ?string $requestId): array
    {
        $this->requireOperation($change, ['update', 'upsert']);
        $this->requirePermission($actor, 'media.quality_review');
        $payload = Validator::make($change['payload'], [
            'media_id' => ['required', 'uuid'],
            'quality_status' => ['required', 'string', Rule::in(['pending', 'acceptable', 'rejected', 'needs_recapture'])],
            'quality_score' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ])->validate();
        $asset = $this->media->find($actor, $payload['media_id']);
        if ((int) $asset->sync_version !== (int) $change['version']) {
            throw new WorkflowConflictException('The media version is stale.', [
                'conflict_code' => 'VERSION_MISMATCH',
                'server_version' => (int) $asset->sync_version,
                'server_payload' => (new MediaAssetResource($asset))->resolve(request()),
            ]);
        }
        $mediaId = $payload['media_id'];
        unset($payload['media_id']);
        $updated = $this->mediaQuality->update($actor, $mediaId, $payload, $ip, $agent, $requestId);

        return $this->result(
            $change,
            $updated->media_asset_id,
            (int) $updated->sync_version,
            (new MediaAssetResource($updated))->resolve(request()),
        );
    }

    /** @param array<string, mixed> $change @return array<string, mixed> */
    private function validation(User $actor, array $change, ?string $ip, ?string $agent, ?string $requestId): array
    {
        $this->requireOperation($change, ['create']);
        $this->requirePermission($actor, 'validation.record_ground_truth');
        if ((int) $change['version'] !== 1) {
            throw new WorkflowConflictException('New validation records must use version 1.', [
                'conflict_code' => 'VERSION_MISMATCH',
                'server_version' => null,
            ]);
        }
        $payload = Validator::make($change['payload'], [
            'validation_session_id' => ['required', 'uuid'],
            'field_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'species_id' => ['sometimes', 'nullable', 'uuid'],
            'location' => ['required', 'array'],
            'location.type' => ['required', 'in:Point'],
            'location.coordinates' => ['required', 'array', 'size:2'],
            'location.coordinates.0' => ['required', 'numeric', 'between:-180,180'],
            'location.coordinates.1' => ['required', 'numeric', 'between:-90,90'],
            'height_m' => ['sometimes', 'nullable', 'numeric', 'between:0,999999.99'],
            'age_years' => ['sometimes', 'nullable', 'numeric', 'between:0,999999.99'],
            'diameter_cm' => ['sometimes', 'nullable', 'numeric', 'between:0,999999.99'],
            'crown_diameter_m' => ['sometimes', 'nullable', 'numeric', 'between:0,999999.99'],
            'health_status' => ['required', 'string', Rule::in(['healthy', 'stressed', 'dead', 'unknown'])],
            'is_tree' => ['required', 'boolean'],
            'photo_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ])->validate();
        $session = $this->validationSessions->find($actor, $payload['validation_session_id']);
        unset($payload['validation_session_id']);
        $record = $this->groundTruth->create($session, $actor, $payload, $ip, $agent, $requestId);

        return $this->result(
            $change,
            $record->ground_truth_id,
            1,
            (new GroundTruthTreeRecordResource($record))->resolve(request()),
        );
    }

    private function requirePermission(User $actor, string $permission): void
    {
        if (! in_array($permission, $this->access->rolesAndPermissions($actor)['permissions'], true)) {
            throw new WorkflowConflictException('The caller cannot apply this mobile mutation.', [
                'conflict_code' => 'PERMISSION_DENIED',
                'required_permission' => $permission,
            ]);
        }
    }

    /** @param array<string, mixed> $change @param list<string> $allowed */
    private function requireOperation(array $change, array $allowed): void
    {
        if (! in_array($change['operation'], $allowed, true)) {
            throw new WorkflowConflictException('The operation is not supported for this mobile entity.', [
                'conflict_code' => 'UNSUPPORTED_OPERATION',
                'allowed_operations' => $allowed,
            ]);
        }
    }

    private function assertVersion(FlightSession $flight, int $clientVersion): void
    {
        if ((int) $flight->sync_version !== $clientVersion) {
            throw new WorkflowConflictException('The flight version is stale.', [
                'conflict_code' => 'VERSION_MISMATCH',
                'server_version' => (int) $flight->sync_version,
                'server_payload' => (new FlightSessionResource($flight))->resolve(request()),
            ]);
        }
    }

    /** @param array<string, mixed> $values @param list<string> $keys @return array<string, mixed> */
    private function onlyPresent(array $values, array $keys): array
    {
        return array_filter(
            array_intersect_key($values, array_flip($keys)),
            fn (mixed $value, string $key): bool => array_key_exists($key, $values),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @param array<string, mixed> $change @param array<string, mixed> $data @return array<string, mixed> */
    private function result(array $change, string $serverId, int $serverVersion, array $data): array
    {
        return [
            'client_id' => $change['client_id'],
            'entity' => $change['entity'],
            'operation' => $change['operation'],
            'status' => 'applied',
            'server_id' => $serverId,
            'server_version' => $serverVersion,
            'data' => $data,
        ];
    }
}
