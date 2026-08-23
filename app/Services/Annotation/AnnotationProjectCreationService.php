<?php

namespace App\Services\Annotation;

use App\Exceptions\WorkflowConflictException;
use App\Models\AnnotationProject;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnnotationProjectCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data, bool $globalScope, ?string $ipAddress, ?string $userAgent, ?string $requestId): AnnotationProject
    {
        return DB::transaction(function () use ($actor, $data, $globalScope, $ipAddress, $userAgent, $requestId): AnnotationProject {
            $organizationId = $actor->organization_id;
            if (! empty($data['mission_id'])) {
                $missions = SurveyMission::query()->whereHas('site', function (Builder $query) use ($actor, $globalScope): void {
                    if (! $globalScope) {
                        $query->where('organization_id', $actor->organization_id);
                    }
                });
                $mission = $missions->findOrFail($data['mission_id']);
                $organizationId = $mission->site->organization_id;
            }

            $normalizedName = Str::lower($data['name']);
            $this->lockIdentity($organizationId.'|'.$normalizedName);
            if (AnnotationProject::query()->where('organization_id', $organizationId)
                ->whereRaw('LOWER(name) = ?', [$normalizedName])->exists()) {
                throw new WorkflowConflictException('An annotation project with this name already exists in the organization.');
            }

            $project = AnnotationProject::query()->create([
                'organization_id' => $organizationId,
                'name' => $data['name'],
                'dataset_type' => $data['dataset_type'],
                'mission_id' => $data['mission_id'] ?? null,
                'status' => $data['status'],
                'created_by' => $actor->user_id,
            ]);

            $this->auditLogger->record(
                'annotation_project.create', 'annotation_projects', $project->annotation_project_id,
                $actor->user_id, null, $project->only([
                    'annotation_project_id', 'organization_id', 'name', 'dataset_type',
                    'mission_id', 'status', 'created_by',
                ]), $ipAddress, $userAgent, $requestId,
            );

            return $project;
        });
    }

    private function lockIdentity(string $identity): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$identity]);
        }
    }
}
