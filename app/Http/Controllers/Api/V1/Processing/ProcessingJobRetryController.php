<?php

namespace App\Http\Controllers\Api\V1\Processing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Processing\ProcessingJobRetryRequest;
use App\Http\Resources\ProcessingJobResource;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\Processing\ProcessingJobRetryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ProcessingJobRetryController extends Controller
{
    // [JOB-04] Queue an idempotent retry without mutating failed history.
    public function __invoke(ProcessingJobRetryRequest $request, string $job, ProcessingJobRetryService $jobs): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 100) {
            throw new BadRequestHttpException('A valid Idempotency-Key header is required.');
        }
        $source = ProcessingJob::query()->whereHas('mission.site', function (Builder $query) use ($actor): void {
            $query->where('organization_id', $actor->organization_id);
        })->findOrFail($job);
        $retry = $jobs->retry($source, $actor, $key, $request->validated('reason'), $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));

        return response()->json([
            'data' => (new ProcessingJobResource($retry))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 202);
    }
}
