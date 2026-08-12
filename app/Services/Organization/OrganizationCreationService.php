<?php

namespace App\Services\Organization;

use App\Exceptions\WorkflowConflictException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationCreationService
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
    ): Organization {
        return DB::transaction(function () use (
            $actor,
            $data,
            $ipAddress,
            $userAgent,
            $requestId,
        ): Organization {
            $normalizedName = Str::lower($data['organization_name']);
            $this->lockName($normalizedName);

            if (Organization::withTrashed()
                ->whereRaw('LOWER(organization_name) = ?', [$normalizedName])
                ->exists()) {
                throw new WorkflowConflictException(
                    'An organization with this name already exists.',
                    ['organization_name' => $data['organization_name']],
                );
            }

            $organization = Organization::query()->create([
                'organization_name' => $data['organization_name'],
                'organization_type' => $data['organization_type'],
                'contact_email' => $data['contact_email'] ?? null,
                'contact_number' => $data['contact_number'] ?? null,
                'address' => $data['address'] ?? null,
                'status' => 'active',
            ]);

            $this->auditLogger->record(
                action: 'organization.create',
                tableName: 'organizations',
                recordId: $organization->organization_id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: [
                    'organization_id' => $organization->organization_id,
                    'organization_name' => $organization->organization_name,
                    'organization_type' => $organization->organization_type,
                    'contact_email' => $organization->contact_email,
                    'contact_number' => $organization->contact_number,
                    'address' => $organization->address,
                    'status' => $organization->status,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $organization;
        });
    }

    private function lockName(string $normalizedName): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                [$normalizedName],
            );
        }
    }
}
