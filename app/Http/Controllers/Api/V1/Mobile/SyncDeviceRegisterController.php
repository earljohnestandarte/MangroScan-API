<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\SyncDeviceRegisterRequest;
use App\Models\User;
use App\Services\Mobile\SyncDeviceRegistrationService;
use Illuminate\Http\JsonResponse;

class SyncDeviceRegisterController extends Controller
{
    // [SYNC-01] Register or refresh the caller's offline-sync installation.
    public function __invoke(
        SyncDeviceRegisterRequest $request,
        SyncDeviceRegistrationService $registration,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $registered = $registration->register(
            actor: $actor,
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => [
                'device_id' => $registered['device']->device_id,
                'server_time' => $registered['server_time']->toIso8601String(),
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
