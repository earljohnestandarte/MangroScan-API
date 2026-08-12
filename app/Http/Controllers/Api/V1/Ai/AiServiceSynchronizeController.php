<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiService;
use App\Models\User;
use App\Services\Ai\AiServiceSynchronizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiServiceSynchronizeController extends Controller
{
    // [AISVC-04] Synchronize authoritative FastAPI model metadata.
    public function __invoke(
        Request $request,
        string $service,
        AiServiceSynchronizationService $synchronization,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $registryService = AiService::query()
            ->select(['ai_service_id', 'base_url', 'enabled', 'health_status'])
            ->findOrFail($service);
        $result = $synchronization->synchronize(
            service: $registryService,
            actor: $actor,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => $result,
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
