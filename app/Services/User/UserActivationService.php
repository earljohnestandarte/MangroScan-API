<?php

namespace App\Services\User;

use App\Exceptions\WorkflowConflictException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class UserActivationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function setActive(
        User $actor,
        User $authorizedTarget,
        bool $isActive,
        ?string $reason,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): User {
        return DB::transaction(function () use ($actor, $authorizedTarget, $isActive, $reason, $ipAddress, $userAgent, $requestId): User {
            $target = User::query()->lockForUpdate()->findOrFail($authorizedTarget->user_id);
            $newStatus = $isActive ? 'active' : 'inactive';

            if (! $isActive && $target->user_id === $actor->user_id) {
                throw new WorkflowConflictException(
                    'You cannot deactivate your own account.',
                    ['user_id' => $target->user_id],
                );
            }

            if ($target->status === $newStatus) {
                throw new WorkflowConflictException(
                    'The user is already in the requested activation state.',
                    ['is_active' => $isActive],
                );
            }

            $oldStatus = $target->status;
            $target->status = $newStatus;
            $target->save();

            if (! $isActive) {
                $target->tokens()->delete();
            }

            $this->auditLogger->record(
                action: 'user.activation.update',
                tableName: 'users',
                recordId: $target->user_id,
                userId: $actor->user_id,
                oldValues: ['status' => $oldStatus],
                newValues: [
                    'status' => $newStatus,
                    'reason' => $reason,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $target->refresh();
        });
    }
}
