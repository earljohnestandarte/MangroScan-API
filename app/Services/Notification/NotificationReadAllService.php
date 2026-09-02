<?php

namespace App\Services\Notification;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class NotificationReadAllService
{
    // [NOTIF-04] Mark only the caller's unread notifications as read.
    public function markAllRead(User $actor): void
    {
        DB::table('notification_logs')
            ->where('user_id', $actor->user_id)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }
}
