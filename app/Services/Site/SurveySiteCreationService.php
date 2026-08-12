<?php

namespace App\Services\Site;

use App\Models\SurveySite;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SurveySiteCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        User $actor,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): SurveySite {
        return DB::transaction(function () use (
            $actor,
            $data,
            $ipAddress,
            $userAgent,
            $requestId,
        ): SurveySite {
            $siteId = (string) Str::uuid();
            $now = Carbon::now();
            $attributes = [
                'site_id' => $siteId,
                'organization_id' => $actor->organization_id,
                'site_name' => $data['site_name'],
                'site_code' => $data['site_code'],
                'description' => $data['description'] ?? null,
                'province' => $data['province'],
                'city_municipality' => $data['city_municipality'],
                'barangay' => $data['barangay'] ?? null,
                'area_hectares' => $data['area_hectares'] ?? null,
                'environment_type' => $data['environment_type'],
                'access_notes' => $data['access_notes'] ?? null,
                'status' => 'active',
                'created_by' => $actor->user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $centerPoint = $data['center_point'] ?? null;

            $this->insert($attributes, $centerPoint);

            $this->auditLogger->record(
                action: 'site.create',
                tableName: 'survey_sites',
                recordId: $siteId,
                userId: $actor->user_id,
                oldValues: null,
                newValues: [
                    ...Arr::except($attributes, ['created_at', 'updated_at']),
                    'center_point' => $centerPoint,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return SurveySite::query()
                ->withCenterPointGeoJson()
                ->findOrFail($siteId);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{type: string, coordinates: array{numeric, numeric}}|null  $centerPoint
     */
    private function insert(array $attributes, ?array $centerPoint): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            DB::table('survey_sites')->insert([
                ...$attributes,
                'center_point' => $centerPoint === null
                    ? null
                    : json_encode($centerPoint, JSON_THROW_ON_ERROR),
            ]);

            return;
        }

        DB::insert(
            <<<'SQL'
                INSERT INTO survey_sites (
                    site_id,
                    organization_id,
                    site_name,
                    site_code,
                    description,
                    province,
                    city_municipality,
                    barangay,
                    center_point,
                    area_hectares,
                    environment_type,
                    access_notes,
                    status,
                    created_by,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?, ?, ?, ?, ?, ?, ?)
                SQL,
            [
                $attributes['site_id'],
                $attributes['organization_id'],
                $attributes['site_name'],
                $attributes['site_code'],
                $attributes['description'],
                $attributes['province'],
                $attributes['city_municipality'],
                $attributes['barangay'],
                $centerPoint === null ? null : json_encode($centerPoint, JSON_THROW_ON_ERROR),
                $attributes['area_hectares'],
                $attributes['environment_type'],
                $attributes['access_notes'],
                $attributes['status'],
                $attributes['created_by'],
                $attributes['created_at'],
                $attributes['updated_at'],
            ],
        );
    }
}
