<?php

namespace App\Services\Report;

use App\Exceptions\WorkflowConflictException;
use App\Jobs\GenerateReportArtifact;
use App\Models\ReportGenerationJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class ReportGenerationQueueService
{
    public function __construct(
        private readonly ScopedReportService $reports,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param array<string, mixed> $data */
    public function queue(
        User $actor,
        string $reportId,
        string $idempotencyKey,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): ReportGenerationJob {
        $payload = $this->canonicalPayload($reportId, $data);
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $reportId, $idempotencyKey, $payload, $fingerprint, $ipAddress, $userAgent, $requestId): ReportGenerationJob {
            $this->lockIdempotency($actor->user_id, $idempotencyKey);
            $existing = ReportGenerationJob::query()
                ->where('created_by', $actor->user_id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()->first();
            if ($existing instanceof ReportGenerationJob) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new WorkflowConflictException(
                        'This idempotency key was already used for another report generation request.',
                        ['idempotency_key' => $idempotencyKey],
                    );
                }

                return $existing;
            }

            $report = $this->reports->find($actor, $reportId, true);
            if ($report->report_status !== 'draft') {
                throw new WorkflowConflictException(
                    'Only draft reports can be queued for generation.',
                    ['report_id' => $report->report_id, 'report_status' => $report->report_status],
                );
            }
            if (ReportGenerationJob::query()->where('report_id', $report->report_id)
                ->whereIn('job_status', ['queued', 'running'])->exists()) {
                throw new WorkflowConflictException(
                    'This report already has an active generation job.',
                    ['report_id' => $report->report_id],
                );
            }

            $job = ReportGenerationJob::query()->create([
                'organization_id' => $actor->organization_id,
                'report_id' => $report->report_id,
                'format' => $payload['format'],
                'options' => $payload['options'],
                'job_status' => 'queued',
                'created_by' => $actor->user_id,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
            ]);
            $this->auditLogger->record(
                action: 'report.generate.queue', tableName: 'report_generation_jobs', recordId: $job->report_generation_job_id,
                userId: $actor->user_id, oldValues: null,
                newValues: ['report_id' => $report->report_id, 'format' => $payload['format'], 'options' => $payload['options'], 'job_status' => 'queued'],
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );
            GenerateReportArtifact::dispatch($job->report_generation_job_id)->afterCommit();

            return $job;
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function canonicalPayload(string $reportId, array $data): array
    {
        $options = $data['options'] ?? [];
        $options += ['page_size' => 'a4', 'orientation' => 'portrait', 'include_source_summary' => true];
        ksort($options);

        return ['report_id' => $reportId, 'format' => $data['format'], 'options' => $options];
    }

    private function lockIdempotency(string $userId, string $key): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$userId.'|report-generation|'.$key]);
        }
    }
}
