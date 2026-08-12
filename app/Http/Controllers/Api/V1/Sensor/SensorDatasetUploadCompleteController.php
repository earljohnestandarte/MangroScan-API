<?php

namespace App\Http\Controllers\Api\V1\Sensor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sensor\SensorDatasetUploadCompleteRequest;
use App\Http\Resources\SensorDatasetResource;
use App\Models\SensorDatasetUploadSession;
use App\Models\User;
use App\Services\Sensor\SensorDatasetUploadCompletionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class SensorDatasetUploadCompleteController extends Controller
{
    // [SDS-02] Verify and atomically finalize one private sensor upload.
    public function __invoke(
        SensorDatasetUploadCompleteRequest $request,
        string $upload,
        SensorDatasetUploadCompletionService $completion,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 100) {
            throw new BadRequestHttpException('A valid Idempotency-Key header is required.');
        }
        $session = SensorDatasetUploadSession::query()
            ->where('initiated_by_user_id', $actor->user_id)
            ->whereHas('flight.mission.site', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            })->findOrFail($upload);
        $dataset = $completion->complete(
            $session, $actor, $key, $request->validated('checksum_sha256'),
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new SensorDatasetResource($dataset))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
