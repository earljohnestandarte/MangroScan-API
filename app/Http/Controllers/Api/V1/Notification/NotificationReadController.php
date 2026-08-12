<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Services\Notification\NotificationReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationReadController extends Controller
{
    // [NOTIF-03] Mark one caller-owned durable notification as read.
    public function __invoke(
        Request $request,
        string $notification,
        NotificationReadService $service,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $updated = $service->markRead($actor, $notification);

        return response()->json([
            'data' => (new NotificationResource($updated))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
