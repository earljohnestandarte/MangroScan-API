<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserIndexRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Tenancy\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class UserIndexController extends Controller
{
    // [USR-01] List users inside the caller's explicitly authorized organization scope.
    public function __invoke(
        UserIndexRequest $request,
        OrganizationScopeService $organizationScope,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $organizationId = $organizationScope->resolve($actor, $validated['org_id'] ?? null);
        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = User::query()->where('organization_id', $organizationId);

        if (array_key_exists('active', $validated) && $validated['active'] !== null) {
            $request->boolean('active')
                ? $query->where('status', 'active')
                : $query->where('status', '!=', 'active');
        }

        if (! empty($validated['search'])) {
            $search = '%'.$validated['search'].'%';
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->whereLike('first_name', $search)
                    ->orWhereLike('middle_name', $search)
                    ->orWhereLike('last_name', $search)
                    ->orWhereLike('email', $search);
            });
        }

        if (! empty($validated['role'])) {
            $roleCode = $validated['role'];
            $query->whereHas('roles', function (Builder $query) use ($roleCode, $organizationId): void {
                $query
                    ->where('role_code', $roleCode)
                    ->where(function (Builder $query) use ($organizationId): void {
                        $query
                            ->whereNull('roles.organization_id')
                            ->orWhere('roles.organization_id', $organizationId);
                    });
            });
        }

        $users = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('user_id')
            ->paginate($perPage);

        return response()->json([
            'data' => UserResource::collection(collect($users->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }
}
