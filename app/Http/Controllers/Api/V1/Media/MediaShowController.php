<?php

namespace App\Http\Controllers\Api\V1\Media;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaAssetResource;
use App\Models\User;
use App\Services\Media\ScopedMediaAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaShowController extends Controller
{
    // [MEDIA-04] Return private-storage-safe metadata without issuing a download pointer.
    public function __invoke(
        Request $request,
        string $media,
        ScopedMediaAssetService $scoped,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $asset = $scoped->find($actor, $media);

        return response()->json([
            'data' => (new MediaAssetResource($asset))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
