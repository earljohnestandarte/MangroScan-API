<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiService;
use App\Models\User;
use App\Services\Ai\AiServiceHealthTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiServiceHealthTestController extends Controller
{
    // [AISVC-03] Health-test one registered FastAPI service.
    public function __invoke(
        Request $request,
        string $service,
        AiServiceHealthTestService $healthTest,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $registryService = AiService::query()
            ->select(['ai_service_id', 'base_url', 'enabled'])
            ->findOrFail($service);
        $result = $healthTest->test(
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
