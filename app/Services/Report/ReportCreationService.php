<?php

namespace App\Services\Report;

use App\Models\Report;
use App\Models\SurveyMission;
use App\Models\SurveySite;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class ReportCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(
        User $actor,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): Report {
        $site = SurveySite::query()
            ->where('organization_id', $actor->organization_id)
            ->findOrFail($data['site_id']);
        $mission = SurveyMission::query()
            ->where('site_id', $site->site_id)
            ->findOrFail($data['mission_id']);

        return DB::transaction(function () use ($actor, $data, $site, $mission, $ipAddress, $userAgent, $requestId): Report {
            $report = Report::query()->create([
                'mission_id' => $mission->mission_id,
                'site_id' => $site->site_id,
                'report_title' => $data['report_title'],
                'report_type' => $data['report_type'],
                'report_status' => 'draft',
                'audience' => $data['audience'] ?? null,
                'summary' => $data['summary'] ?? null,
                'interpretation' => $data['interpretation'] ?? null,
                'limitations' => $data['limitations'] ?? null,
                'recommendations' => $data['recommendations'] ?? null,
                'formats' => $data['formats'] ?? null,
            ]);

            $this->auditLogger->record(
                action: 'report.create', tableName: 'reports', recordId: $report->report_id,
                userId: $actor->user_id, oldValues: null,
                newValues: $report->only([
                    'report_id', 'mission_id', 'site_id', 'report_title', 'report_type', 'report_status',
                    'audience', 'summary', 'interpretation', 'limitations', 'recommendations', 'formats',
                ]),
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return $report->refresh();
        });
    }
}
