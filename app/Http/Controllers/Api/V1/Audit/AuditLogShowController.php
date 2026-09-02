<?php

namespace App\Http\Controllers\Api\V1\Audit;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\EffectiveAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogShowController extends Controller
{
    // [AUD-02] Return immutable audit detail within the caller's authorized organization scope.
    public function __invoke(Request $request, string $audit, EffectiveAccessService $effectiveAccess): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $query = AuditLog::query();
        $canReadAcrossOrganizations = in_array(
            'organizations.manage',
            $effectiveAccess->rolesAndPermissions($actor)['permissions'],
            true,
        );

        if (! $canReadAcrossOrganizations) {
            $query->whereHas('user', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            });
        }

        $log = $query->findOrFail($audit);

        return response()->json([
            'data' => (new AuditLogResource($log))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
