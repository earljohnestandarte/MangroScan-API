<?php

namespace App\Services\Validation;

use App\Exceptions\WorkflowConflictException;
use App\Models\AccuracyMetric;
use App\Models\User;
use App\Models\ValidationSession;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AccuracyRecomputeService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @return Collection<int, AccuracyMetric> */
    public function recompute(
        ValidationSession $session,
        User $actor,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ) {
        return DB::transaction(function () use ($session, $actor, $ipAddress, $userAgent, $requestId) {
            $locked = ValidationSession::query()->lockForUpdate()->findOrFail($session->validation_session_id);
            if ($locked->status !== 'open') {
                throw new WorkflowConflictException('Accuracy can only be recomputed for an open validation session.', ['status' => $locked->status]);
            }
            $matches = DB::table('validation_matches')
                ->where('validation_session_id', $locked->validation_session_id)
                ->get();
            if ($matches->isEmpty()) {
                throw new WorkflowConflictException('At least one validation decision is required before accuracy can be recomputed.');
            }

            $truePositive = $matches->whereIn('match_status', ['matched', 'corrected'])->count();
            $falsePositive = $matches->where('match_status', 'false_positive')->count();
            $falseNegative = $matches->where('match_status', 'false_negative')->count();
            $precision = $this->ratio($truePositive, $truePositive + $falsePositive);
            $recall = $this->ratio($truePositive, $truePositive + $falseNegative);
            $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;
            $species = $matches->whereNotNull('species_correct');
            $height = $matches->whereNotNull('height_error_meters')->map(fn ($row): float => (float) $row->height_error_meters);
            $age = $matches->whereNotNull('age_error_years')->map(fn ($row): float => abs((float) $row->age_error_years));
            $metrics = [
                'count_precision' => [$precision, $truePositive + $falsePositive, 'TP / (TP + FP)'],
                'count_recall' => [$recall, $truePositive + $falseNegative, 'TP / (TP + FN)'],
                'count_f1' => [$f1, $truePositive + $falsePositive + $falseNegative, 'Harmonic mean of count precision and recall'],
                'species_accuracy' => [$this->ratio($species->where('species_correct', true)->count(), $species->count()), $species->count(), 'Correct species decisions / species decisions'],
                'height_rmse' => [$height->isEmpty() ? 0.0 : sqrt($height->map(fn (float $value): float => $value ** 2)->avg()), $height->count(), 'Root mean square of height error in meters'],
                'age_mae' => [$age->isEmpty() ? 0.0 : $age->avg(), $age->count(), 'Mean absolute age error in years'],
            ];
            $computedAt = now('UTC');
            foreach ($metrics as $type => [$value, $sampleSize, $note]) {
                AccuracyMetric::query()->updateOrCreate(
                    ['validation_session_id' => $locked->validation_session_id, 'metric_type' => $type],
                    [
                        'mission_id' => $locked->mission_id,
                        'model_version_id' => null,
                        'metric_value' => number_format((float) $value, 6, '.', ''),
                        'sample_size' => $sampleSize,
                        'computed_at' => $computedAt,
                        'notes' => $note,
                    ],
                );
            }

            $this->auditLogger->record(
                action: 'accuracy.recompute', tableName: 'accuracy_metrics', recordId: $locked->validation_session_id,
                userId: $actor->user_id, oldValues: null,
                newValues: ['validation_session_id' => $locked->validation_session_id, 'metric_types' => array_keys($metrics), 'decision_count' => $matches->count()],
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return AccuracyMetric::query()->where('validation_session_id', $locked->validation_session_id)
                ->orderBy('metric_type')->get();
        });
    }

    private function ratio(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : $numerator / $denominator;
    }
}
