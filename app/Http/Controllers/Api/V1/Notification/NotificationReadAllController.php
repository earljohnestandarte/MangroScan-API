<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notification\NotificationReadAllService;
use Illuminate\Http\Response;
use Illuminate\Http\Request;

class NotificationReadAllController extends Controller
{
    // [NOTIF-04] Mark all caller-owned durable notifications as read.
    public function __invoke(Request $request, NotificationReadAllService $service): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $service->markAllRead($actor);

        return response()->noContent();
    }
}
