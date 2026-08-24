<?php

namespace App\Services\Export;

use App\Exceptions\WorkflowConflictException;
use App\Jobs\GenerateExportArtifact;
use App\Models\ExportJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Report\ScopedReportService;
use Illuminate\Support\Facades\DB;

class ExportQueueService
{
    public function __construct(
        private readonly ScopedReportService $reports,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param array<string, mixed> $data */
    public function queue(User $actor, string $reportId, string $key, array $data, ?string $ip, ?string $agent, ?string $requestId): ExportJob
    {
        $filters = $data['filters'] ?? [];
        $options = $data['options'] ?? [];
        ksort($filters);
        ksort($options);
        $payload = ['report_id' => $reportId, 'format' => $data['format'], 'filters' => $filters, 'options' => $options];
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $reportId, $key, $payload, $fingerprint, $ip, $agent, $requestId): ExportJob {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$actor->user_id.'|export|'.$key]);
            }
            $existing = ExportJob::query()->where('created_by', $actor->user_id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing instanceof ExportJob) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new WorkflowConflictException('This idempotency key was already used for another export request.', ['idempotency_key' => $key]);
                }

                return $existing;
            }
            $report = $this->reports->find($actor, $reportId, true);
            if (ExportJob::query()->where('report_id', $report->report_id)->where('export_type', $payload['format'])
                ->whereIn('job_status', ['queued', 'running'])->exists()) {
                throw new WorkflowConflictException('This report already has an active export of the requested type.', [
                    'report_id' => $report->report_id, 'export_type' => $payload['format'],
                ]);
            }
            $job = ExportJob::query()->create([
                'organization_id' => $actor->organization_id, 'report_id' => $report->report_id,
                'mission_id' => $report->mission_id, 'export_type' => $payload['format'],
                'filters' => $payload['filters'], 'options' => $payload['options'], 'job_status' => 'queued',
                'created_by' => $actor->user_id, 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
            ]);
            $this->auditLogger->record(
                action: 'export.generate.queue', tableName: 'export_jobs', recordId: $job->export_job_id,
                userId: $actor->user_id, oldValues: null,
                newValues: ['report_id' => $report->report_id, 'mission_id' => $report->mission_id, 'export_type' => $payload['format'], 'filters' => $payload['filters'], 'job_status' => 'queued'],
                ipAddress: $ip, userAgent: $agent, requestId: $requestId,
            );
            GenerateExportArtifact::dispatch($job->export_job_id)->afterCommit();

            return $job;
        });
    }
}
