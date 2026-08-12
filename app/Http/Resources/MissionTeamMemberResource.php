<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MissionTeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['mission_team_id' => $this->mission_team_id, 'mission_id' => $this->mission_id,
            'user_id' => $this->user_id, 'team_role' => $this->team_role,
            'assigned_at' => $this->assigned_at?->toIso8601String()];
    }
}
