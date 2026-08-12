<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationUnreadCountController extends Controller
{
    // [NOTIF-02] Count unread durable notifications belonging to the current user.
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $unreadCount = NotificationLog::query()
            ->where('user_id', $actor->user_id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'data' => ['unread_count' => $unreadCount],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
