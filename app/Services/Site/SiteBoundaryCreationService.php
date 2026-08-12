<?php

namespace App\Services\Site;

use App\Models\SiteBoundary;
use App\Models\SurveySite;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SiteBoundaryCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(
        User $actor,
        SurveySite $site,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): SiteBoundary {
        return DB::transaction(function () use ($actor, $site, $data, $ipAddress, $userAgent, $requestId): SiteBoundary {
            $boundaryId = (string) Str::uuid();
            $now = Carbon::now();
            $attributes = [
                'boundary_id' => $boundaryId,
                'site_id' => $site->site_id,
                'boundary_name' => $data['boundary_name'],
                'boundary_type' => $data['boundary_type'],
                'source' => $data['source'] ?? null,
                'created_by' => $actor->user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $this->insert($attributes, $data['boundary_geom']);

            $this->auditLogger->record(
                action: 'boundary.create',
                tableName: 'site_boundaries',
                recordId: $boundaryId,
                userId: $actor->user_id,
                oldValues: null,
                newValues: [
                    ...Arr::except($attributes, ['created_at', 'updated_at']),
                    'boundary_geom' => $data['boundary_geom'],
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return SiteBoundary::query()
                ->withBoundaryGeoJson()
                ->findOrFail($boundaryId);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $geometry
     */
    private function insert(array $attributes, array $geometry): void
    {
        $geoJson = json_encode($geometry, JSON_THROW_ON_ERROR);

        if (DB::getDriverName() !== 'pgsql') {
            DB::table('site_boundaries')->insert([
                ...$attributes,
                'boundary_geom' => $geoJson,
            ]);

            return;
        }

        $isValid = DB::scalar(
            'SELECT ST_IsValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))',
            [$geoJson],
        );

        if (! $isValid) {
            throw ValidationException::withMessages([
                'boundary_geom' => ['The boundary geometry is not a valid polygon.'],
            ]);
        }

        DB::insert(
            <<<'SQL'
                INSERT INTO site_boundaries (
                    boundary_id, site_id, boundary_name, boundary_type, boundary_geom,
                    source, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?, ?, ?, ?)
                SQL,
            [
                $attributes['boundary_id'],
                $attributes['site_id'],
                $attributes['boundary_name'],
                $attributes['boundary_type'],
                $geoJson,
                $attributes['source'],
                $attributes['created_by'],
                $attributes['created_at'],
                $attributes['updated_at'],
            ],
        );
    }
}
