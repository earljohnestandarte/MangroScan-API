<?php

namespace App\Services\Report;

use App\Exceptions\WorkflowConflictException;
use App\Models\Report;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ReportUpdateService
{
    /** @var list<string> */
    private const FIELDS = [
        'report_title', 'report_type', 'report_status', 'audience', 'summary',
        'interpretation', 'limitations', 'recommendations', 'formats',
    ];

    public function __construct(
        private readonly ScopedReportService $reports,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param array<string, mixed> $data */
    public function update(
        User $actor,
        string $id,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): Report {
        return DB::transaction(function () use ($actor, $id, $data, $ipAddress, $userAgent, $requestId): Report {
            $report = $this->reports->find($actor, $id, true);
            if ($report->report_status !== 'draft') {
                throw new WorkflowConflictException(
                    'Only draft reports can be edited or archived.',
                    ['report_id' => $report->report_id, 'report_status' => $report->report_status],
                );
            }

            $old = $report->only(self::FIELDS);
            $report->fill(Arr::only($data, self::FIELDS));
            $report->save();
            $report->refresh();

            $this->auditLogger->record(
                action: 'report.update', tableName: 'reports', recordId: $report->report_id,
                userId: $actor->user_id, oldValues: $old, newValues: $report->only(self::FIELDS),
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return $report;
        });
    }
}
