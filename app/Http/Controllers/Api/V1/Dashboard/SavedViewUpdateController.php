<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\SavedViewUpdateRequest;
use App\Http\Resources\DashboardSavedViewResource;
use App\Services\Dashboard\SavedViewService;
use Illuminate\Http\JsonResponse;

class SavedViewUpdateController extends Controller
{
    public function __invoke(SavedViewUpdateRequest $request, string $view, SavedViewService $service): JsonResponse
    {
        $updated = $service->update($request->user(), $view, $request->validated(), $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));
        return response()->json(['data' => (new DashboardSavedViewResource($updated))->resolve($request), 'meta' => ['request_id' => $request->attributes->get('request_id')]]);
    }
}
