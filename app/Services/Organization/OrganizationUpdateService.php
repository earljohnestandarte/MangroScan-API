<?php

namespace App\Services\Organization;

use App\Exceptions\WorkflowConflictException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationUpdateService
{
    /** @var list<string> */
    private const FIELDS = [
        'organization_name',
        'organization_type',
        'contact_email',
        'contact_number',
        'address',
        'status',
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        User $actor,
        string $organizationId,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): Organization {
        return DB::transaction(function () use (
            $actor,
            $organizationId,
            $data,
            $ipAddress,
            $userAgent,
            $requestId,
        ): Organization {
            $organization = Organization::query()
                ->lockForUpdate()
                ->findOrFail($organizationId);

            if (($data['status'] ?? null) === 'inactive'
                && $organization->organization_id === $actor->organization_id) {
                throw new WorkflowConflictException(
                    'You cannot deactivate your own organization.',
                    ['organization_id' => $organization->organization_id],
                );
            }

            if (array_key_exists('organization_name', $data)) {
                $this->reserveName(
                    organizationId: $organization->organization_id,
                    organizationName: $data['organization_name'],
                );
            }

            $oldValues = $organization->only(self::FIELDS);
            $organization->fill(Arr::only($data, self::FIELDS));
            $organization->save();
            $organization->refresh();

            $this->auditLogger->record(
                action: 'organization.update',
                tableName: 'organizations',
                recordId: $organization->organization_id,
                userId: $actor->user_id,
                oldValues: $oldValues,
                newValues: $organization->only(self::FIELDS),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $organization;
        });
    }

    private function reserveName(string $organizationId, string $organizationName): void
    {
        $normalizedName = Str::lower($organizationName);

        if (DB::getDriverName() === 'pgsql') {
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                [$normalizedName],
            );
        }

        if (Organization::withTrashed()
            ->where('organization_id', '!=', $organizationId)
            ->whereRaw('LOWER(organization_name) = ?', [$normalizedName])
            ->exists()) {
            throw new WorkflowConflictException(
                'An organization with this name already exists.',
                ['organization_name' => $organizationName],
            );
        }
    }
}
