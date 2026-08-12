<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\LoginService;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    // [AUTH-01] Authenticate a web or mobile user and issue a Bearer token.
    public function __invoke(LoginRequest $request, LoginService $login): JsonResponse
    {
        $result = $login->attempt(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            deviceName: $request->input('device_name'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        if ($result === null) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'The provided credentials are invalid.',
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 401);
        }

        return response()->json([
            'data' => $result,
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
