<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\OrganizationIndexRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class OrganizationIndexController extends Controller
{
    // [ORG-01] List the non-deleted organization directory for authorized system administrators.
    public function __invoke(OrganizationIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 25);
        $query = Organization::query();

        if (! empty($validated['search'])) {
            $search = '%'.$validated['search'].'%';
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->whereLike('organization_name', $search)
                    ->orWhereLike('organization_type', $search)
                    ->orWhereLike('contact_email', $search)
                    ->orWhereLike('contact_number', $search)
                    ->orWhereLike('address', $search);
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $organizations = $query
            ->orderBy('organization_name')
            ->orderBy('organization_id')
            ->paginate($perPage);

        return response()->json([
            'data' => OrganizationResource::collection(
                collect($organizations->items()),
            )->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $organizations->currentPage(),
                'per_page' => $organizations->perPage(),
                'total' => $organizations->total(),
                'last_page' => $organizations->lastPage(),
            ],
        ]);
    }
}
