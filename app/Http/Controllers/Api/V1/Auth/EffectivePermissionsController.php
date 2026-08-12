<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\EffectiveAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EffectivePermissionsController extends Controller
{
    // [AUTH-08] Refresh the authenticated caller's roles and effective permissions.
    public function __invoke(Request $request, EffectiveAccessService $effectiveAccess): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $effectiveAccess->rolesAndPermissions($user),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
