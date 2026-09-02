<?php

namespace App\Services\Report;

use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReportDetailService
{
    public function __construct(private readonly ScopedReportService $reports) {}

    /** @return array{report: Report, source_summary: array<string, mixed>} */
    public function get(User $actor, string $id): array
    {
        $report = $this->reports->find($actor, $id);
        $source = DB::table('v_report_source_summary')
            ->where('organization_id', $actor->organization_id)
            ->where('mission_id', $report->mission_id)
            ->where('site_id', $report->site_id)
            ->firstOrFail();

        return ['report' => $report, 'source_summary' => $this->summary($source)];
    }

    /** @return array<string, mixed> */
    private function summary(object $row): array
    {
        return [
            'mission' => [
                'mission_id' => $row->mission_id,
                'mission_code' => $row->mission_code,
                'mission_title' => $row->mission_title,
                'mission_status' => $row->mission_status,
            ],
            'site' => [
                'site_id' => $row->site_id,
                'site_code' => $row->site_code,
                'site_name' => $row->site_name,
            ],
            'trees' => [
                'total' => (int) $row->tree_count,
                'distinct_species' => (int) $row->species_count,
                'validated' => (int) $row->validated_tree_count,
                'unvalidated' => (int) $row->unvalidated_tree_count,
                'rejected' => (int) $row->rejected_tree_count,
            ],
            'validation' => [
                'sessions' => (int) $row->validation_session_count,
                'open_sessions' => (int) $row->open_validation_session_count,
                'completed_sessions' => (int) $row->completed_validation_session_count,
                'ground_truth_records' => (int) $row->ground_truth_count,
            ],
            'accuracy' => $this->accuracy($row),
        ];
    }

    /** @return array<string, string|null> */
    private function accuracy(object $row): array
    {
        $result = [];
        foreach (['species_accuracy', 'count_precision', 'count_recall', 'count_f1', 'height_rmse', 'age_mae'] as $type) {
            $result[$type] = $row->{$type} === null ? null : number_format((float) $row->{$type}, 6, '.', '');
        }

        return $result;
    }
}
