<?php

namespace App\Http\Controllers\Api\V1\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\MediaUploadCompleteRequest;
use App\Http\Resources\MediaAssetResource;
use App\Models\MediaUploadSession;
use App\Models\User;
use App\Services\Media\MediaUploadCompletionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class MediaUploadCompleteController extends Controller
{
    // [MEDIA-03] Verify and atomically finalize one private upload.
    public function __invoke(
        MediaUploadCompleteRequest $request,
        string $upload,
        MediaUploadCompletionService $completion,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw new BadRequestHttpException('A valid Idempotency-Key header is required.');
        }

        $session = MediaUploadSession::query()
            ->where('initiated_by_user_id', $actor->user_id)
            ->whereHas('flight.mission.site', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            })
            ->findOrFail($upload);
        $media = $completion->complete(
            session: $session,
            actor: $actor,
            idempotencyKey: $idempotencyKey,
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new MediaAssetResource($media))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
