<?php

namespace App\Services\Mission;

use App\Exceptions\WorkflowConflictException;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MissionUpdateService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string,mixed> $data */
    public function update(SurveyMission $mission, User $actor, array $data, ?string $siteId, ?string $ip, ?string $agent, ?string $requestId): SurveyMission
    {
        if ($mission->mission_status !== 'planned') {
            throw new WorkflowConflictException('Only planned missions can be updated.', ['current_status' => $mission->mission_status]);
        }
        if ($siteId !== null) {
            $data['site_id'] = $siteId;
        }
        $start = array_key_exists('planned_start_at', $data) ? $data['planned_start_at'] : $mission->planned_start_at;
        $end = array_key_exists('planned_end_at', $data) ? $data['planned_end_at'] : $mission->planned_end_at;
        if ($start !== null && $end !== null && Carbon::parse($end)->lt(Carbon::parse($start))) {
            throw ValidationException::withMessages(['planned_end_at' => ['The planned end must be after or equal to the planned start.']]);
        }

        return DB::transaction(function () use ($mission, $actor, $data, $ip, $agent, $requestId) {
            $fields = ['site_id', 'mission_code', 'mission_title', 'mission_objective', 'planned_start_at', 'planned_end_at', 'coverage_target_hectares'];
            $old = $mission->only($fields);
            $mission->fill($data)->save();
            $mission->refresh();
            $this->auditLogger->record('mission.update', 'survey_missions', $mission->mission_id, $actor->user_id, $old, $mission->only($fields), $ip, $agent, $requestId);

            return $mission;
        });
    }
}
