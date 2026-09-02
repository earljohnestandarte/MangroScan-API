<?php

namespace App\Http\Controllers\Api\V1\Export;

use App\Http\Controllers\Controller;
use App\Http\Requests\Export\ExportStoreRequest;
use App\Models\User;
use App\Services\Export\ExportQueueService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ExportStoreController extends Controller
{
    public function __invoke(ExportStoreRequest $request, string $report, ExportQueueService $exports): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 100) {
            throw new BadRequestHttpException('A valid Idempotency-Key header is required.');
        }
        /** @var User $actor */
        $actor = $request->user();
        $job = $exports->queue($actor, $report, $key, $request->validated(), $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));

        return response()->json(['data' => ['job_id' => $job->export_job_id, 'export_type' => $job->export_type]], 202);
    }
}
