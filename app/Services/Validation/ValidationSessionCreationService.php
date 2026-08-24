<?php

namespace App\Services\Validation;

use App\Exceptions\WorkflowConflictException;
use App\Models\MonitoringPlot;
use App\Models\SurveyMission;
use App\Models\SurveySite;
use App\Models\User;
use App\Models\ValidationSession;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ValidationSessionCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(
        User $actor,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): ValidationSession {
        $site = SurveySite::query()
            ->where('organization_id', $actor->organization_id)
            ->findOrFail($data['site_id']);
        $mission = SurveyMission::query()
            ->where('site_id', $site->site_id)
            ->findOrFail($data['mission_id']);
        $plot = $this->plot($site, $data['plot_id'] ?? null);
        $validator = $this->validator($actor, $data['validated_by']);

        return DB::transaction(function () use ($actor, $data, $site, $mission, $plot, $validator, $ipAddress, $userAgent, $requestId): ValidationSession {
            SurveyMission::query()->lockForUpdate()->findOrFail($mission->mission_id);

            $duplicate = ValidationSession::query()
                ->where('mission_id', $mission->mission_id)
                ->where('site_id', $site->site_id)
                ->where('validated_by', $validator->user_id)
                ->whereDate('validation_date', $data['validation_date'])
                ->where('method', $data['method'])
                ->where('status', 'open')
                ->when(
                    $plot === null,
                    fn (Builder $query) => $query->whereNull('plot_id'),
                    fn (Builder $query) => $query->where('plot_id', $plot->plot_id),
                )
                ->exists();

            if ($duplicate) {
                throw new WorkflowConflictException('An equivalent open validation session already exists.');
            }

            $session = ValidationSession::query()->create([
                'mission_id' => $mission->mission_id,
                'site_id' => $site->site_id,
                'plot_id' => $plot?->plot_id,
                'validated_by' => $validator->user_id,
                'validation_date' => $data['validation_date'],
                'method' => $data['method'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->auditLogger->record(
                action: 'validation.create', tableName: 'validation_sessions', recordId: $session->validation_session_id,
                userId: $actor->user_id, oldValues: null,
                newValues: $session->only([
                    'validation_session_id', 'mission_id', 'site_id', 'plot_id', 'validated_by',
                    'validation_date', 'method', 'status', 'notes',
                ]),
                ipAddress: $ipAddress, userAgent: $userAgent, requestId: $requestId,
            );

            return $session->refresh();
        });
    }

    private function plot(SurveySite $site, ?string $plotId): ?MonitoringPlot
    {
        if ($plotId === null) {
            return null;
        }

        return MonitoringPlot::query()
            ->where('site_id', $site->site_id)
            ->findOrFail($plotId);
    }

    private function validator(User $actor, string $validatorId): User
    {
        return User::query()
            ->where('organization_id', $actor->organization_id)
            ->where('status', 'active')
            ->whereHas('roles', function (Builder $query) use ($actor): void {
                $query
                    ->where(function (Builder $query) use ($actor): void {
                        $query->whereNull('roles.organization_id')
                            ->orWhere('roles.organization_id', $actor->organization_id);
                    })
                    ->whereHas('permissions', fn (Builder $query) => $query
                        ->where('permission_code', 'validation.create'));
            })
            ->findOrFail($validatorId);
    }
}
