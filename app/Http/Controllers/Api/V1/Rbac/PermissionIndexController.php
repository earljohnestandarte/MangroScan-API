<?php

namespace App\Http\Controllers\Api\V1\Rbac;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionIndexController extends Controller
{
    // [RBAC-02] List the global permission catalog.
    public function __invoke(Request $request): JsonResponse
    {
        $permissions = Permission::query()
            ->orderBy('permission_code')
            ->orderBy('permission_id')
            ->get();

        return PermissionResource::collection($permissions)
            ->additional([
                'meta' => [
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ])
            ->response();
    }
}
