<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardSavedViewResource;
use App\Models\DashboardSavedView;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedViewIndexController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        return response()->json([
            'data' => DashboardSavedViewResource::collection(DashboardSavedView::query()->where('user_id', $actor->user_id)->orderBy('view_name')->orderBy('saved_view_id')->get())->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
