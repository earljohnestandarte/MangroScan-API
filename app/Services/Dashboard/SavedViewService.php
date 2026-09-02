<?php

namespace App\Services\Dashboard;

use App\Models\DashboardSavedView;
use App\Models\SurveyMission;
use App\Models\SurveySite;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SavedViewService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data, ?string $ip, ?string $agent, ?string $requestId): DashboardSavedView
    {
        return DB::transaction(function () use ($actor, $data, $ip, $agent, $requestId): DashboardSavedView {
            $this->validateReferences($actor, $data);
            $id = (string) Str::uuid();
            $view = DashboardSavedView::query()->create([
                'saved_view_id' => $id,
                'user_id' => $actor->user_id,
                'site_id' => $data['site_id'] ?? null,
                'mission_id' => $data['mission_id'] ?? null,
                'view_name' => $data['view_name'],
                'filter_config' => $data['filter_config'],
                'map_config' => $data['map_config'],
            ]);
            $this->auditLogger->record('saved_view.create', 'dashboard_saved_views', $id, $actor->user_id, null, ['view_name' => $view->view_name], $ip, $agent, $requestId);
            return $view->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, string $id, array $data, ?string $ip, ?string $agent, ?string $requestId): DashboardSavedView
    {
        return DB::transaction(function () use ($actor, $id, $data, $ip, $agent, $requestId): DashboardSavedView {
            $view = DashboardSavedView::query()->where('user_id', $actor->user_id)->lockForUpdate()->findOrFail($id);
            $this->validateReferences($actor, $data);
            $old = $view->only(['view_name', 'site_id', 'mission_id', 'filter_config', 'map_config']);
            $view->fill($data)->save();
            $this->auditLogger->record('saved_view.update', 'dashboard_saved_views', $view->saved_view_id, $actor->user_id, $old, $view->only(array_keys($old)), $ip, $agent, $requestId);
            return $view->refresh();
        });
    }

    public function delete(User $actor, string $id, ?string $ip, ?string $agent, ?string $requestId): void
    {
        DB::transaction(function () use ($actor, $id, $ip, $agent, $requestId): void {
            $view = DashboardSavedView::query()->where('user_id', $actor->user_id)->lockForUpdate()->findOrFail($id);
            $view->delete();
            $this->auditLogger->record('saved_view.delete', 'dashboard_saved_views', $view->saved_view_id, $actor->user_id, ['view_name' => $view->view_name], null, $ip, $agent, $requestId);
        });
    }

    /** @param array<string, mixed> $data */
    private function validateReferences(User $actor, array $data): void
    {
        if (! empty($data['site_id']) && ! SurveySite::query()->where('organization_id', $actor->organization_id)->find($data['site_id'])) {
            throw (new ModelNotFoundException)->setModel(SurveySite::class, [$data['site_id']]);
        }
        if (! empty($data['mission_id']) && ! SurveyMission::query()->whereHas('site', fn ($query) => $query->where('organization_id', $actor->organization_id))->find($data['mission_id'])) {
            throw (new ModelNotFoundException)->setModel(SurveyMission::class, [$data['mission_id']]);
        }
    }
}
