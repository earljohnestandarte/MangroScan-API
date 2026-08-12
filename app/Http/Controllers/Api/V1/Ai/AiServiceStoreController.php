<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AiServiceStoreRequest;
use App\Http\Resources\AiServiceResource;
use App\Models\User;
use App\Services\Ai\AiServiceRegistrationService;
use Illuminate\Http\JsonResponse;

class AiServiceStoreController extends Controller
{
    // [AISVC-02] Register a trusted inference backend without exposing its key.
    public function __invoke(
        AiServiceStoreRequest $request,
        AiServiceRegistrationService $registration,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $service = $registration->register(
            actor: $actor,
            data: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new AiServiceResource($service))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
