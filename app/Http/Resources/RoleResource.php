<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'role_id' => $this->role_id,
            'organization_id' => $this->organization_id,
            'role_name' => $this->role_name,
            'role_code' => $this->role_code,
            'description' => $this->description,
            'is_system_role' => (bool) $this->is_system_role,
        ];
    }
}
