<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetaCapabilitiesController extends Controller
{
    // [SYS-02] Return the public API capability contract.
    public function __invoke(Request $request, HealthCheckService $healthCheck): JsonResponse
    {
        $health = $healthCheck->check();
        $requestId = $request->attributes->get('request_id');

        if (! $health['available']) {
            return response()->json([
                'error' => [
                    'code' => 'SERVICE_UNAVAILABLE',
                    'message' => 'API capabilities are unavailable while a required service is offline.',
                    'details' => [
                        'db' => $health['db'],
                        'storage' => $health['storage'],
                        'queue' => $health['queue'],
                    ],
                    'request_id' => $requestId,
                ],
            ], 503);
        }

        return response()->json([
            'data' => [
                'api_version' => config('mangroscan.api_version'),
                'features' => config('mangroscan.features'),
                'limits' => config('mangroscan.limits'),
            ],
            'meta' => [
                'request_id' => $requestId,
            ],
        ]);
    }
}
