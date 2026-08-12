<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\NotificationIndexRequest;
use App\Http\Resources\NotificationResource;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class NotificationIndexController extends Controller
{
    // [NOTIF-01] List durable notifications belonging to the current user.
    public function __invoke(NotificationIndexRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $query = NotificationLog::query()->where('user_id', $actor->user_id);

        if (($validated['unread_only'] ?? false) === true) {
            $query->where('is_read', false);
        }

        if (! empty($validated['type'])) {
            $query->where('notification_type', $validated['type']);
        }

        $notifications = $query
            ->orderByDesc('created_at')
            ->orderByDesc('notification_id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => NotificationResource::collection(collect($notifications->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ],
        ]);
    }
}
