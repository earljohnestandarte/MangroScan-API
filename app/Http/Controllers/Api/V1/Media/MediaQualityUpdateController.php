<?php

namespace App\Http\Controllers\Api\V1\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\MediaQualityUpdateRequest;
use App\Http\Resources\MediaAssetResource;
use App\Models\User;
use App\Services\Media\MediaQualityUpdateService;
use Illuminate\Http\JsonResponse;

class MediaQualityUpdateController extends Controller
{
    // [MEDIA-06] Set the quality-control result for tenant-visible media.
    public function __invoke(
        MediaQualityUpdateRequest $request,
        string $media,
        MediaQualityUpdateService $quality,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $asset = $quality->update(
            $actor,
            $media,
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new MediaAssetResource($asset))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
