<?php

namespace App\Services\Mission;

use App\Exceptions\WorkflowConflictException;
use App\Models\FlightSession;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MissionCompletionService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function complete(
        User $actor,
        SurveyMission $mission,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): SurveyMission {
        return DB::transaction(function () use ($actor, $mission, $data, $ipAddress, $userAgent, $requestId): SurveyMission {
            $current = SurveyMission::query()->lockForUpdate()->findOrFail($mission->mission_id);

            if ($current->mission_status !== 'in_progress' || $current->actual_start_at === null) {
                throw new WorkflowConflictException(
                    'Only a started mission can be completed.',
                    [
                        'current_status' => $current->mission_status,
                        'actual_start_at' => $current->actual_start_at?->utc()->toIso8601String(),
                    ],
                );
            }

            $flightStatuses = FlightSession::query()
                ->where('mission_id', $current->mission_id)
                ->orderBy('flight_session_id')
                ->lockForUpdate()
                ->pluck('flight_status');
            $incompleteCounts = $flightStatuses->filter(fn (string $status): bool => $status !== 'completed')
                ->countBy()->sortKeys()->all();

            if ($flightStatuses->isEmpty() || $incompleteCounts !== []) {
                throw new WorkflowConflictException(
                    'Every mission flight must be completed before the mission can be finalized.',
                    [
                        'flight_count' => $flightStatuses->count(),
                        'incomplete_by_status' => $incompleteCounts,
                    ],
                );
            }

            $startedAt = CarbonImmutable::instance($current->actual_start_at)->utc();
            $endedAt = array_key_exists('ended_at', $data) && $data['ended_at'] !== null
                ? CarbonImmutable::parse($data['ended_at'])->utc()
                : CarbonImmutable::now('UTC');
            if (! $endedAt->isAfter($startedAt)) {
                throw new WorkflowConflictException(
                    'Mission completion time must be after its start time.',
                    [
                        'actual_start_at' => $startedAt->toIso8601String(),
                        'ended_at' => $endedAt->toIso8601String(),
                    ],
                );
            }

            $old = [
                'mission_status' => $current->mission_status,
                'actual_end_at' => $current->actual_end_at?->utc()->toIso8601String(),
            ];
            DB::table('survey_missions')->where('mission_id', $current->mission_id)->update([
                'mission_status' => 'completed',
                'actual_end_at' => $endedAt->toIso8601String(),
                'updated_at' => now(),
            ]);

            $this->auditLogger->record(
                action: 'mission.complete', tableName: 'survey_missions', recordId: $current->mission_id,
                userId: $actor->user_id, oldValues: $old,
                newValues: [
                    'mission_status' => 'completed',
                    'actual_end_at' => $endedAt->toIso8601String(),
                    'completion_notes' => $data['completion_notes'] ?? null,
                    'completed_flight_count' => $flightStatuses->count(),
                ],
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return SurveyMission::query()->findOrFail($current->mission_id);
        });
    }
}
