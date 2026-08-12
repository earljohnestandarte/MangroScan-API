<?php

namespace App\Http\Controllers\Api\V1\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\FlightMediaIndexRequest;
use App\Http\Resources\MediaAssetResource;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Flight\ScopedFlightService;
use Illuminate\Http\JsonResponse;

class FlightMediaIndexController extends Controller
{
    // [MEDIA-01] List private-storage-safe metadata for one tenant-visible flight.
    public function __invoke(
        FlightMediaIndexRequest $request,
        string $flight,
        ScopedFlightService $scoped,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $flightModel = $scoped->find($actor, $flight);
        $validated = $request->validated();

        $query = MediaAsset::query()
            ->withCaptureLocationGeoJson()
            ->where('flight_session_id', $flightModel->flight_session_id);

        if (! empty($validated['type'])) {
            $query->where('file_type', $validated['type']);
        }

        foreach (['quality_status', 'processing_status'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        $media = $query
            ->orderByRaw('CASE WHEN captured_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('captured_at')
            ->orderBy('media_asset_id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => MediaAssetResource::collection(collect($media->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $media->currentPage(),
                'per_page' => $media->perPage(),
                'total' => $media->total(),
                'last_page' => $media->lastPage(),
            ],
        ]);
    }
}
