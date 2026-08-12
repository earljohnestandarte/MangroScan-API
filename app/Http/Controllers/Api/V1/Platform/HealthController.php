<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    // [SYS-01] Report API dependency readiness.
    public function __invoke(Request $request, HealthCheckService $healthCheck): JsonResponse
    {
        $result = $healthCheck->check();
        $requestId = $request->attributes->get('request_id');
        $details = [
            'status' => $result['status'],
            'db' => $result['db'],
            'storage' => $result['storage'],
            'queue' => $result['queue'],
            'time' => $result['time'],
        ];

        if (! $result['available']) {
            return response()->json([
                'error' => [
                    'code' => 'SERVICE_UNAVAILABLE',
                    'message' => 'One or more required services are unavailable.',
                    'details' => $details,
                    'request_id' => $requestId,
                ],
            ], 503);
        }

        return response()->json([
            'data' => $details,
            'meta' => [
                'request_id' => $requestId,
            ],
        ]);
    }
}
