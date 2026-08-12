<?php

namespace App\Services\User;

use App\Exceptions\WorkflowConflictException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserUpdateService
{
    /** @var list<string> */
    private const EDITABLE_FIELDS = [
        'first_name',
        'middle_name',
        'last_name',
        'position_title',
        'email',
    ];

    /** @var list<string> */
    private const AUDIT_FIELDS = [
        'organization_id',
        'first_name',
        'middle_name',
        'last_name',
        'position_title',
        'email',
        'status',
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function update(
        User $actor,
        User $authorizedTarget,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): User {
        return DB::transaction(function () use ($actor, $authorizedTarget, $data, $ipAddress, $userAgent, $requestId): User {
            $target = User::query()->lockForUpdate()->findOrFail($authorizedTarget->user_id);

            if (array_key_exists('email', $data)) {
                $this->reserveEmail($target->user_id, $data['email']);
            }

            $oldValues = $target->only(self::AUDIT_FIELDS);
            $target->fill(Arr::only($data, self::EDITABLE_FIELDS));
            $target->save();
            $target->refresh();

            $this->auditLogger->record(
                action: 'user.update',
                tableName: 'users',
                recordId: $target->user_id,
                userId: $actor->user_id,
                oldValues: $oldValues,
                newValues: $target->only(self::AUDIT_FIELDS),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $target;
        });
    }

    private function reserveEmail(string $userId, string $email): void
    {
        $normalizedEmail = Str::lower($email);

        if (DB::getDriverName() === 'pgsql') {
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                [$normalizedEmail],
            );
        }

        if (User::withTrashed()
            ->where('user_id', '!=', $userId)
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->exists()) {
            throw new WorkflowConflictException(
                'A user with this email already exists.',
                ['email' => $email],
            );
        }
    }
}
