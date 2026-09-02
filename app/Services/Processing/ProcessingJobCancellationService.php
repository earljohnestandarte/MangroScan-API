<?php

namespace App\Services\Processing;

use App\Exceptions\WorkflowConflictException;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class ProcessingJobCancellationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function cancel(
        ProcessingJob $job,
        User $actor,
        ?string $reason,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): ProcessingJob {
        return DB::transaction(function () use ($job, $actor, $reason, $ipAddress, $userAgent, $requestId): ProcessingJob {
            $locked = ProcessingJob::query()->lockForUpdate()->findOrFail($job->processing_job_id);

            if (! in_array($locked->job_status, ['queued', 'running'], true)) {
                throw new WorkflowConflictException(
                    'Only a queued or running processing job can be cancelled.',
                    ['job_status' => $locked->job_status],
                );
            }

            $oldStatus = $locked->job_status;
            $cancelledAt = now('UTC');
            $locked->forceFill([
                'job_status' => 'cancelled',
                'cancelled_at' => $cancelledAt,
                'cancelled_by' => $actor->user_id,
                'cancellation_reason' => $reason,
            ])->save();

            DB::table('model_runs')
                ->where('processing_job_id', $locked->processing_job_id)
                ->whereIn('run_status', ['queued', 'running'])
                ->update(['run_status' => 'cancelled']);

            $this->auditLogger->record(
                action: 'processing.cancel',
                tableName: 'processing_jobs',
                recordId: $locked->processing_job_id,
                userId: $actor->user_id,
                oldValues: ['job_status' => $oldStatus],
                newValues: [
                    'job_status' => 'cancelled',
                    'cancelled_at' => $cancelledAt->toIso8601String(),
                    'cancelled_by' => $actor->user_id,
                    'reason' => $reason,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $locked->refresh();
        });
    }
}
