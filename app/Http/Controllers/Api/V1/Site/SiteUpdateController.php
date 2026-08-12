<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\SiteUpdateRequest;
use App\Http\Resources\SurveySiteResource;
use App\Models\User;
use App\Services\Site\ScopedSurveySiteService;
use App\Services\Site\SurveySiteUpdateService;
use Illuminate\Http\JsonResponse;

class SiteUpdateController extends Controller
{
    public function __invoke(SiteUpdateRequest $request, string $site, ScopedSurveySiteService $scopedSites, SurveySiteUpdateService $siteUpdate): JsonResponse
    {
        /** @var User $actor */ $actor = $request->user();
        $target = $scopedSites->find($actor, $site);
        $target = $siteUpdate->update($actor, $target, $request->validated(), $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));

        return response()->json(['data' => (new SurveySiteResource($target))->resolve($request), 'meta' => ['request_id' => $request->attributes->get('request_id')]]);
    }
}
