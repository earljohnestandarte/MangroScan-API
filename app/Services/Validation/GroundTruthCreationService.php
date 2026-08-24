<?php

namespace App\Services\Validation;

use App\Exceptions\WorkflowConflictException;
use App\Models\GroundTruthTreeRecord;
use App\Models\MangroveSpecies;
use App\Models\User;
use App\Models\ValidationSession;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GroundTruthCreationService
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
    ): GroundTruthTreeRecord {
        $speciesId = $this->speciesId($data['species_id'] ?? null);

        return DB::transaction(function () use ($session, $actor, $data, $speciesId, $ipAddress, $userAgent, $requestId): GroundTruthTreeRecord {
            $current = ValidationSession::query()->lockForUpdate()->findOrFail($session->validation_session_id);
            if ($current->status !== 'open') {
                throw new WorkflowConflictException(
                    'Ground-truth records can only be added to an open validation session.',
                    ['status' => $current->status],
                );
            }

            $id = (string) Str::uuid();
            $createdAt = now('UTC');
            $values = [
                'ground_truth_id' => $id,
                'validation_session_id' => $current->validation_session_id,
                'field_code' => $data['field_code'] ?? null,
                'species_id' => $speciesId,
                'measured_height_meters' => $data['height_m'] ?? null,
                'estimated_age_years' => $data['age_years'] ?? null,
                'diameter_cm' => $data['diameter_cm'] ?? null,
                'crown_diameter_m' => $data['crown_diameter_m'] ?? null,
                'health_status' => $data['health_status'],
                'is_tree' => $data['is_tree'],
                'photo_path' => $data['photo_path'] ?? null,
                'remarks' => $data['notes'] ?? null,
                'created_at' => $createdAt,
            ];

            $this->insert($values, $data['location']);

            $this->auditLogger->record(
                action: 'ground_truth.create',
                tableName: 'ground_truth_tree_records',
                recordId: $id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: [
                    'ground_truth_id' => $id,
                    'validation_session_id' => $current->validation_session_id,
                    'field_code' => $values['field_code'],
                    'species_id' => $speciesId,
                    'location' => $data['location'],
                    'measured_height_meters' => $values['measured_height_meters'],
                    'estimated_age_years' => $values['estimated_age_years'],
                    'diameter_cm' => $values['diameter_cm'],
                    'crown_diameter_m' => $values['crown_diameter_m'],
                    'health_status' => $values['health_status'],
                    'is_tree' => $values['is_tree'],
                    'has_photo' => $values['photo_path'] !== null,
                    'remarks' => $values['remarks'],
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return GroundTruthTreeRecord::query()->withGroundLocationGeoJson()->findOrFail($id);
        });
    }

    private function speciesId(?string $speciesId): ?string
    {
        if ($speciesId === null) {
            return null;
        }

        return MangroveSpecies::query()
            ->where('is_active', true)
            ->findOrFail($speciesId)
            ->species_id;
    }

    /** @param array<string, mixed> $values @param array<string, mixed> $location */
    private function insert(array $values, array $location): void
    {
        $geoJson = json_encode($location, JSON_THROW_ON_ERROR);

        if (DB::getDriverName() === 'pgsql') {
            DB::insert(<<<'SQL'
                INSERT INTO ground_truth_tree_records (
                    ground_truth_id, validation_session_id, field_code, species_id, ground_location,
                    measured_height_meters, estimated_age_years, diameter_cm, crown_diameter_m,
                    health_status, is_tree, photo_path, remarks, created_at
                ) VALUES (?, ?, ?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?, ?, ?, ?, ?, ?, ?, ?, ?)
                SQL, [
                $values['ground_truth_id'], $values['validation_session_id'], $values['field_code'],
                $values['species_id'], $geoJson, $values['measured_height_meters'],
                $values['estimated_age_years'], $values['diameter_cm'], $values['crown_diameter_m'],
                $values['health_status'], $values['is_tree'], $values['photo_path'],
                $values['remarks'], $values['created_at'],
            ]);

            return;
        }

        DB::table('ground_truth_tree_records')->insert($values + ['ground_location' => $geoJson]);
    }
}
