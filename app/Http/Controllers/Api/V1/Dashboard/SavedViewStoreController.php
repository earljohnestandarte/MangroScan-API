<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\SavedViewStoreRequest;
use App\Http\Resources\DashboardSavedViewResource;
use App\Models\User;
use App\Services\Dashboard\SavedViewService;
use Illuminate\Http\JsonResponse;

class SavedViewStoreController extends Controller
{
    public function __invoke(SavedViewStoreRequest $request, SavedViewService $service): JsonResponse
    {
        /** @var User $actor */
        $view = $service->create($request->user(), $request->validated(), $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));
        return response()->json(['data' => (new DashboardSavedViewResource($view))->resolve($request), 'meta' => ['request_id' => $request->attributes->get('request_id')]], 201);
    }
}
