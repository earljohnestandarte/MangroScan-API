<?php

namespace App\Services\Mission;

use App\Exceptions\WorkflowConflictException;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class MissionDeleteService
{
    public function __construct(
        private readonly AuditLogger $auditLogger
    ) {}

    public function delete(
        SurveyMission $mission,
        User $actor,
        ?string $ip,
        ?string $agent,
        ?string $requestId
    ): void {
        DB::transaction(function () use (
            $mission,
            $actor,
            $ip,
            $agent,
            $requestId
        ): void {
            $mission = SurveyMission::query()
                ->whereKey($mission->mission_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($mission->mission_status !== 'planned') {
                throw new WorkflowConflictException(
                    'Only planned missions can be archived.',
                    ['current_status' => $mission->mission_status],
                );
            }

            $old = [
                'mission_id' => $mission->mission_id,
                'site_id' => $mission->site_id,
                'mission_code' => $mission->mission_code,
                'mission_title' => $mission->mission_title,
                'mission_status' => $mission->mission_status,
            ];

            $mission->delete();

            $this->auditLogger->record(
                action: 'mission.delete',
                tableName: 'survey_missions',
                recordId: $mission->mission_id,
                userId: $actor->user_id,
                oldValues: $old,
                newValues: [
                    'deleted_at' => $mission->deleted_at,
                ],
                ipAddress: $ip,
                userAgent: $agent,
                requestId: $requestId,
            );
        });
    }
}
