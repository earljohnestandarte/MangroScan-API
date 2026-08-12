<?php

namespace App\Services\Mission;

use App\Models\SurveyMission;
use App\Models\SurveySite;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class MissionCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, SurveySite $site, array $data, ?string $ipAddress, ?string $userAgent, ?string $requestId): SurveyMission
    {
        return DB::transaction(function () use ($actor, $site, $data, $ipAddress, $userAgent, $requestId): SurveyMission {
            $mission = SurveyMission::query()->create([
                'site_id' => $site->site_id,
                'mission_code' => $data['mission_code'],
                'mission_title' => $data['mission_title'],
                'mission_objective' => $data['mission_objective'],
                'planned_start_at' => $data['planned_start_at'] ?? null,
                'planned_end_at' => $data['planned_end_at'] ?? null,
                'coverage_target_hectares' => $data['coverage_target_hectares'] ?? null,
                'mission_status' => 'planned',
                'created_by' => $actor->user_id,
            ]);

            $this->auditLogger->record(
                action: 'mission.create', tableName: 'survey_missions', recordId: $mission->mission_id,
                userId: $actor->user_id, oldValues: null,
                newValues: $mission->only(['mission_id', 'site_id', 'mission_code', 'mission_title', 'mission_objective', 'planned_start_at', 'planned_end_at', 'coverage_target_hectares', 'mission_status', 'created_by']),
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return $mission->refresh();
        });
    }
}
