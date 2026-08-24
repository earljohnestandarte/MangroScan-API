<?php

namespace App\Services\Report;

use App\Exceptions\WorkflowConflictException;
use App\Models\Report;
use App\Models\ReportGenerationJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ReportGenerationExecutionService
{
    public function __construct(
        private readonly ReportDetailService $details,
        private readonly SimplePdfRenderer $renderer,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(string $jobId): void
    {
        $job = DB::transaction(function () use ($jobId): ?ReportGenerationJob {
            $job = ReportGenerationJob::query()->lockForUpdate()->findOrFail($jobId);
            if ($job->job_status === 'completed' || $job->job_status === 'running') {
                return null;
            }
            $job->update(['job_status' => 'running', 'started_at' => now('UTC'), 'error_message' => null]);

            return $job->refresh();
        });
        if ($job === null) {
            return;
        }

        $storageKey = null;
        try {
            /** @var User $actor */
            $actor = (new User)->forceFill([
                'user_id' => $job->created_by,
                'organization_id' => $job->organization_id,
            ]);
            $detail = $this->details->get($actor, $job->report_id);
            /** @var Report $report */
            $report = $detail['report'];
            if ($report->report_status !== 'draft') {
                throw new WorkflowConflictException('The report is no longer eligible for generation.');
            }
            $bytes = $this->renderer->render($report, $detail['source_summary'], $job->options ?? []);
            $fileName = (Str::slug($report->report_title) ?: 'report').'-'.$job->report_generation_job_id.'.pdf';
            $storageKey = 'report-artifacts/'.$actor->organization_id.'/'.$report->report_id.'/'.$fileName;
            $disk = Storage::disk(config('mangroscan.media.disk', 'local'));
            if (! $disk->put($storageKey, $bytes)) {
                throw new WorkflowConflictException('The generated report could not be written to private storage.');
            }

            DB::transaction(function () use ($job, $report, $actor, $fileName, $storageKey, $bytes): void {
                $currentJob = ReportGenerationJob::query()->lockForUpdate()->findOrFail($job->report_generation_job_id);
                $currentReport = Report::query()->lockForUpdate()->findOrFail($report->report_id);
                if ($currentReport->report_status !== 'draft') {
                    throw new WorkflowConflictException('The report is no longer eligible for generation.');
                }
                $currentJob->update([
                    'job_status' => 'completed', 'file_name' => $fileName, 'storage_key' => $storageKey,
                    'file_size_bytes' => strlen($bytes), 'checksum_sha256' => hash('sha256', $bytes),
                    'completed_at' => now('UTC'), 'error_message' => null,
                ]);
                $currentReport->update(['report_status' => 'generated', 'generated_by' => $actor->user_id]);
                $this->auditLogger->record(
                    action: 'report.generate.complete', tableName: 'reports', recordId: $currentReport->report_id,
                    userId: $actor->user_id, oldValues: ['report_status' => 'draft'],
                    newValues: ['report_status' => 'generated', 'generation_job_id' => $currentJob->report_generation_job_id, 'file_name' => $fileName],
                    ipAddress: null, userAgent: 'queue:report-generation', requestId: null,
                );
            });
        } catch (Throwable $exception) {
            if ($storageKey !== null) {
                Storage::disk(config('mangroscan.media.disk', 'local'))->delete($storageKey);
            }
            ReportGenerationJob::query()->where('report_generation_job_id', $jobId)->update([
                'job_status' => 'failed', 'completed_at' => null,
                'file_name' => null, 'storage_key' => null, 'file_size_bytes' => null, 'checksum_sha256' => null,
                'error_message' => Str::limit($exception->getMessage(), 5000, ''), 'updated_at' => now('UTC'),
            ]);
            throw $exception;
        }
    }
}
