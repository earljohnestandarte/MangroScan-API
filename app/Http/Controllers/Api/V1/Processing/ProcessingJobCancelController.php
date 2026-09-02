<?php

namespace App\Http\Controllers\Api\V1\Processing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Processing\ProcessingJobCancelRequest;
use App\Http\Resources\ProcessingJobResource;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\Processing\ProcessingJobCancellationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class ProcessingJobCancelController extends Controller
{
    // [JOB-05] Cancel tenant-owned queued/running work and its unfinished run plan.
    public function __invoke(
        ProcessingJobCancelRequest $request,
        string $job,
        ProcessingJobCancellationService $jobs,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $target = ProcessingJob::query()
            ->whereHas('mission.site', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            })
            ->findOrFail($job);
        $cancelled = $jobs->cancel(
            $target,
            $actor,
            $request->validated('reason'),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new ProcessingJobResource($cancelled))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
