<?php

namespace App\Http\Controllers\Api\V1\Rbac;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleIndexController extends Controller
{
    // [RBAC-01] List global and current-organization roles.
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $roles = Role::query()
            ->where(function ($query) use ($user): void {
                $query
                    ->whereNull('organization_id')
                    ->orWhere('organization_id', $user->organization_id);
            })
            ->orderBy('role_name')
            ->orderBy('role_id')
            ->get();

        return RoleResource::collection($roles)
            ->additional([
                'meta' => [
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ])
            ->response();
    }
}
