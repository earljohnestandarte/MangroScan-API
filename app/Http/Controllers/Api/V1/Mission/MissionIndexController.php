<?php

namespace App\Http\Controllers\Api\V1\Mission;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mission\MissionIndexRequest;
use App\Http\Resources\SurveyMissionResource;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Site\ScopedSurveySiteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class MissionIndexController extends Controller
{
    // [MSN-01] List missions reachable through sites owned by the caller's organization.
    public function __invoke(
        MissionIndexRequest $request,
        ScopedSurveySiteService $scopedSites,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 25);
        $query = SurveyMission::query()
            ->whereHas('site', fn (Builder $query) => $query->where('organization_id', $actor->organization_id));

        if (! empty($validated['site_id'])) {
            $site = $scopedSites->find($actor, $validated['site_id']);
            $query->where('site_id', $site->site_id);
        }

        if (! empty($validated['status'])) {
            $query->where('mission_status', $validated['status']);
        }

        if (! empty($validated['from'])) {
            $query->where('planned_start_at', '>=', Carbon::parse($validated['from'])->startOfDay());
        }

        if (! empty($validated['to'])) {
            $query->where('planned_start_at', '<=', Carbon::parse($validated['to'])->endOfDay());
        }

        if (! empty($validated['search'])) {
            $search = '%'.$validated['search'].'%';
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->whereLike('mission_code', $search)
                    ->orWhereLike('mission_title', $search)
                    ->orWhereLike('mission_objective', $search);
            });
        }

        $missions = $query
            ->orderByRaw('CASE WHEN planned_start_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('planned_start_at')
            ->orderBy('mission_id')
            ->paginate($perPage);

        return response()->json([
            'data' => SurveyMissionResource::collection(collect($missions->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $missions->currentPage(),
                'per_page' => $missions->perPage(),
                'total' => $missions->total(),
                'last_page' => $missions->lastPage(),
            ],
        ]);
    }
}
