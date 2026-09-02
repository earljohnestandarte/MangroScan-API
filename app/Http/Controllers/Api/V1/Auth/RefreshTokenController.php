<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Http\JsonResponse;

class RefreshTokenController extends Controller
{
    public function __invoke(RefreshTokenRequest $request, RefreshTokenService $service): JsonResponse
    {
        return response()->json(['data' => $service->rotate($request->string('refresh_token')->toString(), $request->ip(), $request->userAgent(), $request->attributes->get('request_id')), 'meta' => ['request_id' => $request->attributes->get('request_id')]]);
    }
}
