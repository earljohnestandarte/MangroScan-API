<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\SiteAccessPermissionStoreRequest;
use App\Http\Resources\SiteAccessPermissionResource;
use App\Models\User;
use App\Services\Site\ScopedSurveySiteService;
use App\Services\Site\SiteAccessPermissionCreationService;
use Illuminate\Http\JsonResponse;

class SiteAccessPermissionStoreController extends Controller
{
    // [PERMIT-02] Record one audited access permission for a tenant-visible site.
    public function __invoke(
        SiteAccessPermissionStoreRequest $request,
        string $site,
        ScopedSurveySiteService $scopedSites,
        SiteAccessPermissionCreationService $creation,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $permission = $creation->create(
            actor: $actor,
            site: $scopedSites->find($actor, $site),
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new SiteAccessPermissionResource($permission))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
