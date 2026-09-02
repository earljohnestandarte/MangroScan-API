<?php

namespace App\Services\Tree;

use App\Exceptions\WorkflowConflictException;
use App\Models\ProcessingJob;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class MissionLayerBuildService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function queue(
        SurveyMission $mission,
        User $actor,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): ProcessingJob {
        sort($data['layer_types'], SORT_STRING);

        return DB::transaction(function () use ($mission, $actor, $data, $ipAddress, $userAgent, $requestId): ProcessingJob {
            $current = SurveyMission::query()->lockForUpdate()->findOrFail($mission->mission_id);
            $treeTypes = array_intersect($data['layer_types'], ['tree_points', 'species_map', 'canopy_height']);
            if ($treeTypes !== [] && ! DB::table('tree_observations')->where('mission_id', $current->mission_id)->whereNull('deleted_at')->exists()) {
                throw new WorkflowConflictException('The requested tree layers require canonical tree observations.');
            }
            $productIds = DB::table('photogrammetry_products')->where('mission_id', $current->mission_id)
                ->orderBy('product_id')->pluck('product_id')->all();
            if (in_array('orthomosaic', $data['layer_types'], true)
                && ! DB::table('photogrammetry_products')->where('mission_id', $current->mission_id)->where('product_type', 'orthomosaic')->exists()) {
                throw new WorkflowConflictException('The orthomosaic layer requires a completed orthomosaic photogrammetry product.');
            }
            $active = ProcessingJob::query()->where('mission_id', $current->mission_id)
                ->where('job_type', 'photogrammetry')->whereIn('job_status', ['queued', 'running'])
                ->lockForUpdate()->get();
            foreach ($active as $job) {
                $queuedTypes = $job->input_summary['layer_build']['layer_types'] ?? [];
                if (array_intersect($queuedTypes, $data['layer_types']) !== []) {
                    throw new WorkflowConflictException('A build for one or more requested layer types is already active.', [
                        'processing_job_id' => $job->processing_job_id,
                    ]);
                }
            }

            $job = ProcessingJob::query()->create([
                'mission_id' => $current->mission_id,
                'flight_session_id' => null,
                'job_type' => 'photogrammetry',
                'job_status' => 'queued',
                'input_summary' => [
                    'contract_version' => 1,
                    'layer_build' => [
                        'layer_types' => $data['layer_types'],
                        'parameters' => $data['parameters'] ?? null,
                        'photogrammetry_product_ids' => $productIds,
                    ],
                ],
                'created_by' => $actor->user_id,
                'request_fingerprint' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
            ]);
            $this->auditLogger->record(
                action: 'layers.build.queue', tableName: 'processing_jobs', recordId: $job->processing_job_id,
                userId: $actor->user_id, oldValues: null,
                newValues: ['mission_id' => $current->mission_id, 'job_status' => 'queued', 'layer_types' => $data['layer_types'], 'product_ids' => $productIds],
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return $job;
        });
    }
}
