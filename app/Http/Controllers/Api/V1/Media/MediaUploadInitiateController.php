<?php

namespace App\Http\Controllers\Api\V1\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\MediaUploadInitiateRequest;
use App\Models\User;
use App\Services\Flight\ScopedFlightService;
use App\Services\Media\MediaUploadInitiationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class MediaUploadInitiateController extends Controller
{
    // [MEDIA-02] Initiate an idempotent private media upload.
    public function __invoke(
        MediaUploadInitiateRequest $request,
        string $flight,
        ScopedFlightService $flights,
        MediaUploadInitiationService $uploads,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw new BadRequestHttpException('A valid Idempotency-Key header is required.');
        }

        $result = $uploads->initiate(
            actor: $actor,
            flight: $flights->find($actor, $flight),
            idempotencyKey: $idempotencyKey,
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );
        $session = $result['session'];

        return response()->json([
            'data' => [
                'upload_id' => $session->upload_id,
                'storage_key' => $session->storage_key,
                'upload_url' => $result['upload_url'],
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
