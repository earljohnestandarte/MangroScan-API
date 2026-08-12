<?php

namespace App\Services\Mission;

use App\Exceptions\WorkflowConflictException;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MissionStartService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function start(
        User $actor,
        SurveyMission $mission,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): SurveyMission {
        return DB::transaction(function () use ($actor, $mission, $data, $ipAddress, $userAgent, $requestId): SurveyMission {
            $current = SurveyMission::query()->lockForUpdate()->findOrFail($mission->mission_id);

            if ($current->mission_status !== 'planned') {
                throw new WorkflowConflictException(
                    'Only a planned mission can be started.',
                    ['current_status' => $current->mission_status],
                );
            }
            if ($current->approved_by === null) {
                throw new WorkflowConflictException(
                    'Mission approval is required before starting.',
                    ['approved' => false],
                );
            }

            $startedAt = array_key_exists('started_at', $data) && $data['started_at'] !== null
                ? CarbonImmutable::parse($data['started_at'])->utc()
                : CarbonImmutable::now('UTC');
            $old = [
                'mission_status' => $current->mission_status,
                'actual_start_at' => $current->actual_start_at?->toIso8601String(),
            ];

            DB::table('survey_missions')
                ->where('mission_id', $current->mission_id)
                ->update([
                    'mission_status' => 'in_progress',
                    'actual_start_at' => $startedAt->toIso8601String(),
                    'updated_at' => now(),
                ]);

            $this->auditLogger->record(
                action: 'mission.start', tableName: 'survey_missions', recordId: $current->mission_id,
                userId: $actor->user_id, oldValues: $old,
                newValues: ['mission_status' => 'in_progress', 'actual_start_at' => $startedAt->toIso8601String()],
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return SurveyMission::query()->findOrFail($current->mission_id);
        });
    }
}
