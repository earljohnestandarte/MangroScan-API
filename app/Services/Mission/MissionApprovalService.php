<?php

namespace App\Services\Mission;

use App\Exceptions\WorkflowConflictException;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class MissionApprovalService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function decide(SurveyMission $mission, User $actor, string $decision, ?string $notes, ?string $ip, ?string $agent, ?string $requestId): SurveyMission
    {
        return DB::transaction(function () use ($mission, $actor, $decision, $notes, $ip, $agent, $requestId) {
            $current = SurveyMission::query()->lockForUpdate()->findOrFail($mission->mission_id);
            if ($current->mission_status !== 'planned' || $current->approved_by !== null) {
                throw new WorkflowConflictException('This mission already has a final approval decision.', ['current_status' => $current->mission_status, 'approved_by' => $current->approved_by]);
            }$old = ['mission_status' => $current->mission_status, 'approved_by' => $current->approved_by];
            if ($decision === 'approved') {
                $current->approved_by = $actor->user_id;
            } else {
                $current->mission_status = 'cancelled';
            }$current->save();
            $this->auditLogger->record('mission.approval', 'survey_missions', $current->mission_id, $actor->user_id, $old, ['decision' => $decision, 'notes' => $notes, 'mission_status' => $current->mission_status, 'approved_by' => $current->approved_by], $ip, $agent, $requestId);

            return $current->refresh();
        });
    }
}
