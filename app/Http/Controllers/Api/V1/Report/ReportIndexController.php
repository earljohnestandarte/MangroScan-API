<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ReportIndexRequest;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use App\Models\User;
use App\Services\Mission\ScopedMissionService;
use App\Services\Site\ScopedSurveySiteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class ReportIndexController extends Controller
{
    // [RPT-01] List report registry records visible through current-tenant site lineage.
    public function __invoke(
        ReportIndexRequest $request,
        ScopedMissionService $missions,
        ScopedSurveySiteService $sites,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $query = Report::query()
            ->whereHas('site', fn (Builder $query) => $query->where('organization_id', $actor->organization_id))
            ->whereHas('mission.site', fn (Builder $query) => $query->where('organization_id', $actor->organization_id));

        if (! empty($validated['site_id'])) {
            $site = $sites->find($actor, $validated['site_id']);
            $query->where('reports.site_id', $site->site_id);
        }

        if (! empty($validated['mission_id'])) {
            $mission = $missions->find($actor, $validated['mission_id']);
            $query->where('reports.mission_id', $mission->mission_id);
        }

        foreach (['status', 'type'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where('report_'.$filter, $validated[$filter]);
            }
        }

        $reports = $query
            ->orderByDesc('created_at')
            ->orderByDesc('report_id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => ReportResource::collection(collect($reports->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $reports->currentPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
                'last_page' => $reports->lastPage(),
            ],
        ]);
    }
}
