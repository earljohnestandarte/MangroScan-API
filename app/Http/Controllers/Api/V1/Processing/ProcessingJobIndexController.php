<?php

namespace App\Http\Controllers\Api\V1\Processing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Processing\ProcessingJobIndexRequest;
use App\Http\Resources\ProcessingJobResource;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\Flight\ScopedFlightService;
use App\Services\Mission\ScopedMissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class ProcessingJobIndexController extends Controller
{
    // [JOB-01] List tenant-visible durable processing jobs.
    public function __invoke(
        ProcessingJobIndexRequest $request,
        ScopedMissionService $missions,
        ScopedFlightService $flights,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();

        if (! empty($validated['mission_id'])) {
            $missions->find($actor, $validated['mission_id']);
        }

        if (! empty($validated['flight_id'])) {
            $flights->find($actor, $validated['flight_id']);
        }

        $query = ProcessingJob::query()
            ->whereHas('mission.site', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            });

        foreach (['mission_id', 'flight_id'] as $filter) {
            if (! empty($validated[$filter])) {
                $column = $filter === 'flight_id' ? 'flight_session_id' : 'mission_id';
                $query->where($column, $validated[$filter]);
            }
        }

        foreach (['status', 'type'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where('job_'.$filter, $validated[$filter]);
            }
        }

        $jobs = $query
            ->orderByDesc('queued_at')
            ->orderByDesc('processing_job_id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => ProcessingJobResource::collection(collect($jobs->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $jobs->currentPage(),
                'per_page' => $jobs->perPage(),
                'total' => $jobs->total(),
                'last_page' => $jobs->lastPage(),
            ],
        ]);
    }
}
