<?php

namespace App\Services\Mission;

use App\Exceptions\WorkflowConflictException;
use App\Models\MissionTeamMember;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MissionTeamReplacementService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param list<array{user_id:string,team_role:string}> $members */
    public function replace(SurveyMission $mission, User $actor, array $members, ?string $ip, ?string $agent, ?string $requestId)
    {
        if ($mission->mission_status !== 'planned' || $mission->approved_by !== null) {
            throw new WorkflowConflictException('Mission team can only change before approval.', ['current_status' => $mission->mission_status, 'approved' => $mission->approved_by !== null]);
        }
        $org = $mission->site()->value('organization_id');
        $ids = array_values(array_unique(array_column($members, 'user_id')));
        if (User::query()->where('organization_id', $org)->whereIn('user_id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['members' => ['Every member must be an active user in the mission organization.']]);
        }

        return DB::transaction(function () use ($mission, $actor, $members, $ip, $agent, $requestId) {
            $old = $mission->teamMembers()->orderBy('team_role')->orderBy('user_id')->get(['user_id', 'team_role'])->toArray();
            $mission->teamMembers()->delete();
            $now = now();
            foreach ($members as $m) {
                MissionTeamMember::query()->create(['mission_team_id' => (string) Str::uuid(), 'mission_id' => $mission->mission_id, 'user_id' => $m['user_id'], 'team_role' => $m['team_role'], 'assigned_at' => $now]);
            }$team = $mission->teamMembers()->orderBy('team_role')->orderBy('user_id')->get();
            $this->auditLogger->record('mission.team.replace', 'mission_team_members', $mission->mission_id, $actor->user_id, ['members' => $old], ['members' => $team->map->only(['user_id', 'team_role'])->all()], $ip, $agent, $requestId);

            return $team;
        });
    }
}
