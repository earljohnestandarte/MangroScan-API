<?php

namespace App\Services\Site;

use App\Models\SiteAccessPermission;
use App\Models\SurveySite;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SiteAccessPermissionCreationService
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
    ): SiteAccessPermission {
        return DB::transaction(function () use ($actor, $site, $data, $ipAddress, $userAgent, $requestId): SiteAccessPermission {
            $id = (string) Str::uuid();
            $now = Carbon::now();
            $attributes = [
                'access_permission_id' => $id,
                'site_id' => $site->site_id,
                'permit_title' => $data['permit_title'],
                'issuing_agency' => $data['issuing_agency'],
                'permit_number' => $data['permit_number'] ?? null,
                'valid_from' => $data['valid_from'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'document_path' => $data['document_path'] ?? null,
                'status' => $data['status'],
                'created_by' => $actor->user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            SiteAccessPermission::query()->create($attributes);
            $this->auditLogger->record(
                action: 'site_access_permission.create',
                tableName: 'site_access_permissions',
                recordId: $id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: [
                    ...$attributes,
                    'created_at' => $now->toIso8601String(),
                    'updated_at' => $now->toIso8601String(),
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return SiteAccessPermission::query()->findOrFail($id);
        });
    }
}
