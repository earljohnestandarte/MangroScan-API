<?php

namespace App\Http\Controllers\Api\V1\Processing;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModelRunResource;
use App\Http\Resources\ProcessingJobResource;
use App\Models\ProcessingJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcessingJobShowController extends Controller
{
    // [JOB-03] Show one tenant-visible job, its runs, output and error evidence.
    public function __invoke(Request $request, string $job): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $processingJob = ProcessingJob::query()
            ->whereHas('mission.site', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            })
            ->findOrFail($job);
        $runs = $processingJob->modelRuns()
            ->orderBy('created_at')
            ->orderBy('model_run_id')
            ->get();

        return response()->json([
            'data' => [
                'job' => (new ProcessingJobResource($processingJob))->resolve($request),
                'model_runs' => ModelRunResource::collection($runs)->resolve($request),
                'output_summary' => $processingJob->output_summary,
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
