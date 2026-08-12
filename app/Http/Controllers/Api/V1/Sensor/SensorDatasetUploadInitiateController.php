<?php

namespace App\Http\Controllers\Api\V1\Sensor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sensor\SensorDatasetUploadInitiateRequest;
use App\Models\User;
use App\Services\Flight\ScopedFlightService;
use App\Services\Sensor\SensorDatasetUploadInitiationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class SensorDatasetUploadInitiateController extends Controller
{
    public function __invoke(SensorDatasetUploadInitiateRequest $request, string $flight, ScopedFlightService $flights, SensorDatasetUploadInitiationService $uploads): JsonResponse
    {
        /** @var User $actor */ $actor = $request->user();
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 100) {
            throw new BadRequestHttpException('A valid Idempotency-Key header is required.');
        }$result = $uploads->initiate($actor, $flights->find($actor, $flight), $key, $request->validated(), $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));
        $session = $result['session'];

        return response()->json(['data' => ['upload_id' => $session->upload_id, 'storage_key' => $session->storage_key, 'upload_url' => $result['upload_url']], 'meta' => ['request_id' => $request->attributes->get('request_id')]], 201);
    }
}
