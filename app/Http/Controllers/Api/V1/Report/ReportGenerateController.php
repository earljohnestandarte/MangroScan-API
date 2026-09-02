<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ReportGenerateRequest;
use App\Models\User;
use App\Services\Report\ReportGenerationQueueService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ReportGenerateController extends Controller
{
    // [RPT-05] Idempotently enqueue private PDF generation for one visible draft.
    public function __invoke(ReportGenerateRequest $request, string $report, ReportGenerationQueueService $generation): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 100) {
            throw new BadRequestHttpException('A valid Idempotency-Key header is required.');
        }
        /** @var User $actor */
        $actor = $request->user();
        $job = $generation->queue(
            $actor, $report, $key, $request->validated(), $request->ip(), $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json(['data' => [
            'job_id' => $job->report_generation_job_id,
            'report_id' => $job->report_id,
            'status' => $job->job_status,
        ]], 202);
    }
}
