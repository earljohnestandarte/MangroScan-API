<?php

namespace App\Services\Report;

use App\Exceptions\DownstreamServiceException;
use App\Exceptions\WorkflowConflictException;
use App\Models\Report;
use App\Models\ReportGenerationJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReportApprovalService
{
    public function __construct(
        private readonly ScopedReportService $reports,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function decide(
        User $actor,
        string $reportId,
        string $decision,
        ?string $notes,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): Report {
        return DB::transaction(function () use ($actor, $reportId, $decision, $notes, $ipAddress, $userAgent, $requestId): Report {
            $report = $this->reports->find($actor, $reportId, true);
            if ($report->report_status !== 'generated') {
                throw new WorkflowConflictException(
                    'Only generated reports can receive an approval decision.',
                    ['report_id' => $report->report_id, 'report_status' => $report->report_status],
                );
            }

            $artifact = ReportGenerationJob::query()
                ->where('organization_id', $actor->organization_id)
                ->where('report_id', $report->report_id)
                ->where('job_status', 'completed')
                ->latest('completed_at')->latest('report_generation_job_id')
                ->first();
            if (! $artifact instanceof ReportGenerationJob) {
                throw new WorkflowConflictException(
                    'The generated report has no completed artifact evidence.',
                    ['report_id' => $report->report_id],
                );
            }
            if ($decision === 'approved') {
                try {
                    $exists = is_string($artifact->storage_key)
                        && Storage::disk(config('mangroscan.media.disk', 'local'))->exists($artifact->storage_key);
                } catch (Throwable) {
                    $exists = false;
                }
                if (! $exists) {
                    throw new DownstreamServiceException(
                        'The generated report artifact is unavailable in private storage.',
                        503,
                        'SERVICE_UNAVAILABLE',
                    );
                }
            }

            $old = [
                'report_status' => $report->report_status,
                'generated_by' => $report->generated_by,
                'approved_by' => $report->approved_by,
            ];
            if ($decision === 'approved') {
                $report->fill(['report_status' => 'approved', 'approved_by' => $actor->user_id]);
            } else {
                $report->fill(['report_status' => 'draft', 'generated_by' => null, 'approved_by' => null]);
            }
            $report->save();
            $report->refresh();

            $this->auditLogger->record(
                action: 'report.approval', tableName: 'reports', recordId: $report->report_id,
                userId: $actor->user_id, oldValues: $old,
                newValues: [
                    'decision' => $decision,
                    'notes' => $notes,
                    'generation_job_id' => $artifact->report_generation_job_id,
                    'report_status' => $report->report_status,
                    'generated_by' => $report->generated_by,
                    'approved_by' => $report->approved_by,
                ],
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return $report;
        });
    }
}
