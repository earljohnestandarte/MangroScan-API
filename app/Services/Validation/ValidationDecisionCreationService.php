<?php

namespace App\Services\Validation;

use App\Exceptions\WorkflowConflictException;
use App\Models\GroundTruthTreeRecord;
use App\Models\MangroveSpecies;
use App\Models\TreeObservation;
use App\Models\User;
use App\Models\ValidationMatch;
use App\Models\ValidationSession;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ValidationDecisionCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(
        ValidationSession $session,
        User $actor,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): ValidationMatch {
        return DB::transaction(function () use ($session, $actor, $data, $ipAddress, $userAgent, $requestId): ValidationMatch {
            $current = ValidationSession::query()->lockForUpdate()->findOrFail($session->validation_session_id);
            if ($current->status !== 'open') {
                throw new WorkflowConflictException(
                    'Validation decisions can only be added to an open validation session.',
                    ['status' => $current->status],
                );
            }

            $truth = $this->truth($current, $data['ground_truth_id'] ?? null);
            $tree = $this->tree($current, $data['tree_observation_id'] ?? null);
            $acceptedSpeciesId = $this->speciesId($data['accepted_species_id'] ?? null);
            $this->rejectDuplicate($current, $truth, $tree);

            $metrics = $this->metrics($truth, $tree, $data, $acceptedSpeciesId);
            $id = (string) Str::uuid();
            $validatedAt = now('UTC');
            $values = [
                'validation_match_id' => $id,
                'validation_session_id' => $current->validation_session_id,
                'ground_truth_id' => $truth?->ground_truth_id,
                'tree_observation_id' => $tree?->tree_observation_id,
                'match_status' => $data['decision'],
                'accepted_species_id' => $acceptedSpeciesId,
                'accepted_height_m' => $data['accepted_height_m'] ?? null,
                'accepted_age_years' => $data['accepted_age_years'] ?? null,
                'notes' => $data['notes'] ?? null,
                'validation_evidence' => isset($data['validation_evidence'])
                    ? json_encode($data['validation_evidence'], JSON_THROW_ON_ERROR)
                    : null,
                ...$metrics,
                'validated_by' => $actor->user_id,
                'validated_at' => $validatedAt,
            ];

            $this->insert($values, $data['corrected_geometry'] ?? null);
            $this->applyTreeOutcome($tree, $data, $acceptedSpeciesId);

            $this->auditLogger->record(
                action: 'validation.decision.create',
                tableName: 'validation_matches',
                recordId: $id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: [
                    ...$values,
                    'corrected_geometry' => $data['corrected_geometry'] ?? null,
                    'validation_evidence' => $data['validation_evidence'] ?? null,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return ValidationMatch::query()->withCorrectedGeometryGeoJson()->findOrFail($id);
        });
    }

    private function truth(ValidationSession $session, ?string $id): ?GroundTruthTreeRecord
    {
        if ($id === null) {
            return null;
        }

        return GroundTruthTreeRecord::query()
            ->where('validation_session_id', $session->validation_session_id)
            ->lockForUpdate()
            ->findOrFail($id);
    }

    private function tree(ValidationSession $session, ?string $id): ?TreeObservation
    {
        if ($id === null) {
            return null;
        }

        return TreeObservation::query()
            ->where('mission_id', $session->mission_id)
            ->lockForUpdate()
            ->findOrFail($id);
    }

    private function speciesId(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return MangroveSpecies::query()->where('is_active', true)->findOrFail($id)->species_id;
    }

    private function rejectDuplicate(
        ValidationSession $session,
        ?GroundTruthTreeRecord $truth,
        ?TreeObservation $tree,
    ): void {
        $duplicate = ValidationMatch::query()
            ->where('validation_session_id', $session->validation_session_id)
            ->where(function ($query) use ($truth, $tree): void {
                if ($truth !== null) {
                    $query->orWhere('ground_truth_id', $truth->ground_truth_id);
                }
                if ($tree !== null) {
                    $query->orWhere('tree_observation_id', $tree->tree_observation_id);
                }
            })
            ->exists();

        if ($duplicate) {
            throw new WorkflowConflictException('This ground-truth record or tree observation already has a decision in the session.');
        }
    }

    /** @param array<string, mixed> $data @return array<string, float|bool|null> */
    private function metrics(
        ?GroundTruthTreeRecord $truth,
        ?TreeObservation $tree,
        array $data,
        ?string $acceptedSpeciesId,
    ): array {
        if ($truth === null || $tree === null) {
            return [
                'distance_error_meters' => null,
                'species_correct' => null,
                'height_error_meters' => null,
                'age_error_years' => null,
            ];
        }

        $referenceSpecies = $acceptedSpeciesId ?? $truth->species_id;
        $referenceHeight = $data['accepted_height_m'] ?? $truth->measured_height_meters;
        $referenceAge = $data['accepted_age_years'] ?? $truth->estimated_age_years;
        $treeGeometry = $this->geometry('tree_observations', 'tree_observation_id', $tree->tree_observation_id, 'tree_location');
        $referenceGeometry = $data['corrected_geometry']
            ?? $this->geometry('ground_truth_tree_records', 'ground_truth_id', $truth->ground_truth_id, 'ground_location');

        return [
            'distance_error_meters' => $this->distance($treeGeometry, $referenceGeometry),
            'species_correct' => $referenceSpecies === null || $tree->final_species_id === null
                ? null
                : $tree->final_species_id === $referenceSpecies,
            'height_error_meters' => $referenceHeight === null || $tree->final_height_meters === null
                ? null
                : round(abs((float) $tree->final_height_meters - (float) $referenceHeight), 4),
            'age_error_years' => $referenceAge === null || $tree->final_estimated_age_years === null
                ? null
                : round(abs((float) $tree->final_estimated_age_years - (float) $referenceAge), 4),
        ];
    }

    /** @param array<string, mixed>|null $geometry */
    private function applyTreeOutcome(?TreeObservation $tree, array $data, ?string $acceptedSpeciesId): void
    {
        if ($tree === null) {
            return;
        }

        $updates = ['validation_status' => match ($data['decision']) {
            'matched' => 'validated',
            'corrected' => 'corrected',
            'false_positive' => 'rejected',
        }, 'updated_at' => now('UTC')];

        if ($data['decision'] === 'corrected') {
            foreach ([
                'accepted_height_m' => 'final_height_meters',
                'accepted_age_years' => 'final_estimated_age_years',
            ] as $input => $column) {
                if (array_key_exists($input, $data) && $data[$input] !== null) {
                    $updates[$column] = $data[$input];
                }
            }
            if ($acceptedSpeciesId !== null) {
                $updates['final_species_id'] = $acceptedSpeciesId;
            }
        }

        DB::table('tree_observations')->where('tree_observation_id', $tree->tree_observation_id)->update($updates);
        if ($data['decision'] === 'corrected' && isset($data['corrected_geometry'])) {
            $geoJson = json_encode($data['corrected_geometry'], JSON_THROW_ON_ERROR);
            if (DB::getDriverName() === 'pgsql') {
                DB::update(
                    'UPDATE tree_observations SET tree_location = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) WHERE tree_observation_id = ?',
                    [$geoJson, $tree->tree_observation_id],
                );
            } else {
                DB::table('tree_observations')->where('tree_observation_id', $tree->tree_observation_id)
                    ->update(['tree_location' => $geoJson]);
            }
        }
    }

    /** @param array<string, mixed>|null $geometry */
    private function insert(array $values, ?array $geometry): void
    {
        $geoJson = $geometry === null ? null : json_encode($geometry, JSON_THROW_ON_ERROR);
        if (DB::getDriverName() === 'pgsql') {
            DB::insert(<<<'SQL'
                INSERT INTO validation_matches (
                    validation_match_id, validation_session_id, ground_truth_id, tree_observation_id,
                    match_status, accepted_species_id, accepted_height_m, accepted_age_years,
                    corrected_geometry, notes, validation_evidence, distance_error_meters,
                    species_correct, height_error_meters, age_error_years, validated_by, validated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?, ?::jsonb, ?, ?, ?, ?, ?, ?)
                SQL, [
                $values['validation_match_id'], $values['validation_session_id'], $values['ground_truth_id'],
                $values['tree_observation_id'], $values['match_status'], $values['accepted_species_id'],
                $values['accepted_height_m'], $values['accepted_age_years'], $geoJson, $values['notes'],
                $values['validation_evidence'], $values['distance_error_meters'], $values['species_correct'],
                $values['height_error_meters'], $values['age_error_years'], $values['validated_by'],
                $values['validated_at'],
            ]);

            return;
        }

        DB::table('validation_matches')->insert($values + ['corrected_geometry' => $geoJson]);
    }

    /** @return array{type:string,coordinates:array{0:float|int,1:float|int}} */
    private function geometry(string $table, string $key, string $id, string $column): array
    {
        $value = DB::getDriverName() === 'pgsql'
            ? DB::table($table)->where($key, $id)->selectRaw("ST_AsGeoJSON($column)::json AS geometry")->value('geometry')
            : DB::table($table)->where($key, $id)->value($column);

        return is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : (array) $value;
    }

    /** @param array{coordinates:array{0:float|int,1:float|int}} $from @param array{coordinates:array{0:float|int,1:float|int}} $to */
    private function distance(array $from, array $to): float
    {
        [$fromLon, $fromLat] = $from['coordinates'];
        [$toLon, $toLat] = $to['coordinates'];
        $latDelta = deg2rad((float) $toLat - (float) $fromLat);
        $lonDelta = deg2rad((float) $toLon - (float) $fromLon);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad((float) $fromLat)) * cos(deg2rad((float) $toLat)) * sin($lonDelta / 2) ** 2;
        $a = min(1.0, max(0.0, $a));

        return round(6371008.8 * 2 * atan2(sqrt($a), sqrt(1 - $a)), 4);
    }
}
