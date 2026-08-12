<?php

namespace App\Services\Auth;

use App\Exceptions\WorkflowConflictException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PasswordResetRequestService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function send(string $email, ?string $ipAddress, ?string $userAgent, ?string $requestId): void
    {
        $user = User::query()->with('organization')->where('email', $email)->first();
        if ($user === null || ! $user->isActive() || $user->organization?->status !== 'active') {
            throw new NotFoundHttpException('No active account was found for this email address.');
        }

        $repository = Password::broker()->getRepository();
        if ($repository->recentlyCreatedToken($user)) {
            throw new WorkflowConflictException('A password reset was requested recently. Please wait before retrying.');
        }

        DB::transaction(function () use ($repository, $user, $ipAddress, $userAgent, $requestId): void {
            $token = $repository->create($user);
            $this->auditLogger->record(
                action: 'auth.password.reset.requested',
                tableName: 'users',
                recordId: $user->user_id,
                userId: $user->user_id,
                oldValues: null,
                newValues: ['delivery' => 'email'],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );
            $user->sendPasswordResetNotification($token);
        });
    }
}
