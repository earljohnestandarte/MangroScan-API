<?php

namespace App\Services\Notification;

use App\Exceptions\WorkflowConflictException;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class NotificationReadService
{
    // [NOTIF-03] Apply the caller-owned unread-to-read transition atomically.
    public function markRead(User $actor, string $notificationId): NotificationLog
    {
        return DB::transaction(function () use ($actor, $notificationId): NotificationLog {
            $notification = NotificationLog::query()
                ->where('user_id', $actor->user_id)
                ->lockForUpdate()
                ->findOrFail($notificationId);

            if ($notification->is_read) {
                throw new WorkflowConflictException('This notification is already marked as read.', [
                    'notification_id' => $notification->notification_id,
                    'is_read' => true,
                ]);
            }

            $notification->is_read = true;
            $notification->save();

            return $notification->refresh();
        });
    }
}
