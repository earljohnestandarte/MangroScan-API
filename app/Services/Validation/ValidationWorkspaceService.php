<?php

namespace App\Services\Validation;

use App\Models\AccuracyMetric;
use App\Models\GeospatialLayer;
use App\Models\GroundTruthTreeRecord;
use App\Models\TreeObservation;
use App\Models\User;
use App\Models\ValidationMatch;
use App\Models\ValidationSession;
use Illuminate\Support\Collection;

class ValidationWorkspaceService
{
    public function __construct(private readonly ScopedValidationSessionService $sessions) {}

    /**
     * @return array{
     *     session: ValidationSession,
     *     observations: Collection<int, TreeObservation>,
     *     ground_truth_records: Collection<int, GroundTruthTreeRecord>,
     *     matches: Collection<int, ValidationMatch>,
     *     metrics: Collection<int, AccuracyMetric>,
     *     layers: Collection<int, GeospatialLayer>
     * }
     */
    public function get(User $actor, string $id): array
    {
        $session = $this->sessions->find($actor, $id);
        $session->load([
            'mission:mission_id,site_id,mission_code,mission_title,mission_status',
            'site:site_id,site_code,site_name',
            'plot:plot_id,site_id,plot_code,plot_name',
            'validator:user_id,first_name,middle_name,last_name,position_title',
        ]);

        return [
            'session' => $session,
            'observations' => TreeObservation::query()
                ->withGeometryGeoJson()
                ->where('mission_id', $session->mission_id)
                ->orderBy('tree_code')
                ->orderBy('tree_observation_id')
                ->get(),
            'ground_truth_records' => GroundTruthTreeRecord::query()
                ->withGroundLocationGeoJson()
                ->where('validation_session_id', $session->validation_session_id)
                ->orderBy('created_at')
                ->orderBy('ground_truth_id')
                ->get(),
            'matches' => ValidationMatch::query()
                ->withCorrectedGeometryGeoJson()
                ->where('validation_session_id', $session->validation_session_id)
                ->orderBy('validated_at')
                ->orderBy('validation_match_id')
                ->get(),
            'metrics' => AccuracyMetric::query()
                ->where('validation_session_id', $session->validation_session_id)
                ->where('mission_id', $session->mission_id)
                ->orderBy('metric_type')
                ->orderByDesc('computed_at')
                ->orderBy('accuracy_metric_id')
                ->get(),
            'layers' => GeospatialLayer::query()
                ->where('mission_id', $session->mission_id)
                ->orderBy('layer_type')
                ->orderBy('layer_name')
                ->orderBy('layer_id')
                ->get(),
        ];
    }
}
