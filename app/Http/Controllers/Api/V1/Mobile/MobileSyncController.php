<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobileSyncRequest;
use App\Models\User;
use App\Services\Mobile\MobileSyncService;
use Illuminate\Http\JsonResponse;

class MobileSyncController extends Controller
{
    public function __invoke(
        MobileSyncRequest $request,
        MobileSyncService $sync,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $result = $sync->sync(
            actor: $actor,
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => [
                'applied' => $result['applied'],
                'conflicts' => $result['conflicts'],
                'server_changes' => $result['server_changes'],
            ],
            'meta' => [
                'cursor' => $result['cursor'],
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
