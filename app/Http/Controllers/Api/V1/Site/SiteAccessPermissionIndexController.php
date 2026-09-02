<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteAccessPermissionResource;
use App\Models\SiteAccessPermission;
use App\Models\User;
use App\Services\Site\ScopedSurveySiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteAccessPermissionIndexController extends Controller
{
    // [PERMIT-01] List access permissions belonging to one tenant-visible site.
    public function __invoke(Request $request, string $site, ScopedSurveySiteService $scopedSites): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $surveySite = $scopedSites->find($actor, $site);
        $permissions = SiteAccessPermission::query()
            ->where('site_id', $surveySite->site_id)
            ->orderByDesc('valid_until')
            ->orderBy('permit_title')
            ->orderBy('access_permission_id')
            ->get();

        return response()->json([
            'data' => SiteAccessPermissionResource::collection($permissions)->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
