<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\SiteBoundaryUpdateRequest;
use App\Http\Resources\SiteBoundaryResource;
use App\Models\User;
use App\Services\Site\ScopedSiteBoundaryService;
use App\Services\Site\SiteBoundaryUpdateService;
use Illuminate\Http\JsonResponse;

class SiteBoundaryUpdateController extends Controller
{
    public function __invoke(SiteBoundaryUpdateRequest $request, string $boundary, ScopedSiteBoundaryService $scoped, SiteBoundaryUpdateService $updater): JsonResponse
    {
        /** @var User $actor */ $actor = $request->user();
        $target = $scoped->find($actor, $boundary);
        $target = $updater->update($actor, $target, $request->validated(), $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));

        return response()->json(['data' => (new SiteBoundaryResource($target))->resolve($request), 'meta' => ['request_id' => $request->attributes->get('request_id')]]);
    }
}
