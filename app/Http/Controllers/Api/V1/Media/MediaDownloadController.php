<?php

namespace App\Http\Controllers\Api\V1\Media;

use App\Contracts\Media\PrivateDownloadUrlIssuer;
use App\Http\Controllers\Controller;
use App\Services\Media\ScopedMediaAssetService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        string $media,
        ScopedMediaAssetService $scoped,
        PrivateDownloadUrlIssuer $issuer,
    ): JsonResponse {
        $asset = $scoped->find($request->user(), $media);

        $expiresAt = CarbonImmutable::now('UTC')
            ->addMinutes((int) config('mangroscan.media.download_url_ttl_minutes', 10));

        $target = $issuer->issue(
            (string) config('mangroscan.media.disk', config('filesystems.default')),
            $asset->storage_key,
            $expiresAt,
        );

        return response()->json([
            'data' => [
                'url' => $target['url'],
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
