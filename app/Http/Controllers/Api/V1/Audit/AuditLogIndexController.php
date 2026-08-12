<?php

namespace App\Http\Controllers\Api\V1\Audit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\EffectiveAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class AuditLogIndexController extends Controller
{
    // [AUD-01] Search the immutable audit trail within the authorized organization scope.
    public function __invoke(
        AuditLogIndexRequest $request,
        EffectiveAccessService $effectiveAccess,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $canReadAcrossOrganizations = in_array(
            'organizations.manage',
            $effectiveAccess->rolesAndPermissions($actor)['permissions'],
            true,
        );

        $query = AuditLog::query();

        if (! $canReadAcrossOrganizations) {
            $query->whereHas('user', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            });
        }

        if (! empty($validated['user_id'])) {
            $users = User::query()->withTrashed();

            if (! $canReadAcrossOrganizations) {
                $users->where('organization_id', $actor->organization_id);
            }

            $user = $users->findOrFail($validated['user_id']);
            $query->where('user_id', $user->user_id);
        }

        foreach (['action', 'table_name', 'record_id'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($validated['from'])->utc());
        }

        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', CarbonImmutable::parse($validated['to'])->utc());
        }

        $logs = $query
            ->orderByDesc('created_at')
            ->orderByDesc('audit_log_id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => AuditLogResource::collection(collect($logs->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }
}
