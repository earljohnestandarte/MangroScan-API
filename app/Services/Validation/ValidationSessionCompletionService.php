<?php

namespace App\Services\Validation;

use App\Exceptions\WorkflowConflictException;
use App\Models\User;
use App\Models\ValidationSession;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ValidationSessionCompletionService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function complete(
        ValidationSession $session,
        User $actor,
        string $notes,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): ValidationSession {
        return DB::transaction(function () use ($session, $actor, $notes, $ipAddress, $userAgent, $requestId): ValidationSession {
            $locked = ValidationSession::query()->lockForUpdate()->findOrFail($session->validation_session_id);
            if ($locked->status !== 'open') {
                throw new WorkflowConflictException('Only an open validation session can be completed.', ['status' => $locked->status]);
            }
            $latestDecision = DB::table('validation_matches')
                ->where('validation_session_id', $locked->validation_session_id)
                ->max('validated_at');
            if ($latestDecision === null) {
                throw new WorkflowConflictException('At least one validation decision is required before completion.');
            }
            $metrics = DB::table('accuracy_metrics')->where('validation_session_id', $locked->validation_session_id);
            $latestMetric = (clone $metrics)->min('computed_at');
            if ((clone $metrics)->distinct()->count('metric_type') < 6
                || $latestMetric === null
                || CarbonImmutable::parse($latestMetric)->lt(CarbonImmutable::parse($latestDecision))) {
                throw new WorkflowConflictException('Accuracy metrics must be recomputed after the latest validation decision.');
            }

            $old = $locked->only(['status', 'notes', 'completed_at', 'completed_by']);
            $locked->forceFill([
                'status' => 'completed',
                'notes' => $notes,
                'completed_at' => now('UTC'),
                'completed_by' => $actor->user_id,
            ])->save();
            $this->auditLogger->record(
                action: 'validation.complete', tableName: 'validation_sessions', recordId: $locked->validation_session_id,
                userId: $actor->user_id, oldValues: $old,
                newValues: $locked->only(['status', 'notes', 'completed_at', 'completed_by']),
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return $locked->refresh();
        });
    }
}
