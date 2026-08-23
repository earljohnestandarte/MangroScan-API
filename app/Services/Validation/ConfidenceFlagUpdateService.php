<?php

namespace App\Services\Validation;

use App\Models\ConfidenceFlag;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class ConfidenceFlagUpdateService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ConfidenceReviewService $reviews,
    ) {}

    /** @param array<string, mixed> $data */
    public function update(
        string $resultId,
        User $actor,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): ConfidenceFlag {
        return DB::transaction(function () use ($resultId, $actor, $data, $ipAddress, $userAgent, $requestId): ConfidenceFlag {
            $target = $this->target($resultId, $actor->organization_id);
            if (isset($data['assigned_to'])) {
                $validAssignee = User::query()
                    ->where('organization_id', $actor->organization_id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->whereKey($data['assigned_to'])
                    ->exists();
                if (! $validAssignee) {
                    abort(404);
                }
            }

            $flag = ConfidenceFlag::query()
                ->where('result_type', $target['result_type'])
                ->where('result_id', $resultId)
                ->lockForUpdate()
                ->first();
            $old = $flag?->only(['status', 'severity', 'review_note', 'assigned_to', 'reason', 'resolution_notes']);
            if (! $flag instanceof ConfidenceFlag) {
                $flag = new ConfidenceFlag([
                    'mission_id' => $target['mission_id'],
                    'result_id' => $resultId,
                    'result_type' => $target['result_type'],
                    'severity' => $this->reviews->severity((float) $target['confidence_score']),
                    'created_by' => $actor->user_id,
                ]);
            }
            $flag->fill($data);
            $flag->save();

            $this->auditLogger->record(
                action: 'confidence_review.update',
                tableName: 'confidence_flags',
                recordId: $flag->confidence_flag_id,
                userId: $actor->user_id,
                oldValues: $old,
                newValues: $flag->only(['result_id', 'result_type', 'status', 'severity', 'review_note', 'assigned_to', 'reason', 'resolution_notes']),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $flag->refresh();
        });
    }

    /** @return array{mission_id:string,result_type:string,confidence_score:mixed} */
    private function target(string $resultId, string $organizationId): array
    {
        $definitions = [
            'detection' => ['tree_observations', 'tree_observation_id', 'detection_confidence', false],
            'species' => ['species_classification_results', 'classification_result_id', 'confidence_score', true],
            'height' => ['canopy_height_estimations', 'height_estimation_id', 'height_confidence_score', true],
            'age' => ['age_estimations', 'age_estimation_id', 'confidence_score', true],
        ];
        foreach ($definitions as $type => [$table, $id, $score, $joinTree]) {
            $query = DB::table($table.' as result');
            if ($joinTree) {
                $query->join('tree_observations as tree', 'tree.tree_observation_id', '=', 'result.tree_observation_id');
            } else {
                $query->join('tree_observations as tree', 'tree.tree_observation_id', '=', 'result.tree_observation_id');
            }
            $row = $query->join('survey_missions as mission', 'mission.mission_id', '=', 'tree.mission_id')
                ->join('survey_sites as site', 'site.site_id', '=', 'mission.site_id')
                ->where('site.organization_id', $organizationId)
                ->whereNull('site.deleted_at')->whereNull('mission.deleted_at')->whereNull('tree.deleted_at')
                ->where('result.'.$id, $resultId)
                ->whereNotNull('result.'.$score)
                ->selectRaw('tree.mission_id, result.'.$score.' as confidence_score')
                ->first();
            if ($row !== null) {
                return ['mission_id' => $row->mission_id, 'result_type' => $type, 'confidence_score' => $row->confidence_score];
            }
        }
        abort(404);
    }
}
