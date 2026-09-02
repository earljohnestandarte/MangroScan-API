<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobileSyncStatusRequest;
use App\Models\User;
use App\Services\Mobile\MobileSyncStatusService;
use Illuminate\Http\JsonResponse;

class MobileSyncStatusController extends Controller
{
    public function __invoke(
        MobileSyncStatusRequest $request,
        MobileSyncStatusService $status,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $status->status(
                $actor,
                $request->validated('device_id'),
            ),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
