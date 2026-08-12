<?php

namespace App\Http\Controllers\Api\V1\Processing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Processing\ProcessingJobStoreRequest;
use App\Models\User;
use App\Services\Flight\ScopedFlightService;
use App\Services\Mission\ScopedMissionService;
use App\Services\Processing\ProcessingJobCreationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ProcessingJobStoreController extends Controller
{
    // [JOB-02] Queue a durable AI processing job and its model runs.
    public function __invoke(
        ProcessingJobStoreRequest $request,
        ScopedMissionService $missions,
        ScopedFlightService $flights,
        ProcessingJobCreationService $jobs,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw new BadRequestHttpException('A valid Idempotency-Key header is required.');
        }

        $validated = $request->validated();
        $mission = $missions->find($actor, $validated['mission_id']);
        $flight = isset($validated['flight_session_id'])
            ? $flights->find($actor, $validated['flight_session_id'])
            : null;
        $job = $jobs->create(
            mission: $mission,
            flight: $flight,
            actor: $actor,
            idempotencyKey: $idempotencyKey,
            data: $validated,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => [
                'processing_job_id' => $job->processing_job_id,
                'job_status' => $job->job_status,
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 202);
    }
}
