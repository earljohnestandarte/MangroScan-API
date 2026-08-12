<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordForgotRequest;
use App\Services\Auth\PasswordResetRequestService;
use Illuminate\Http\JsonResponse;

class PasswordForgotController extends Controller
{
    // [AUTH-06] Issue a password-reset notification for an active account.
    public function __invoke(PasswordForgotRequest $request, PasswordResetRequestService $resets): JsonResponse
    {
        $resets->send(
            $request->string('email')->toString(),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => ['message' => 'Password reset instructions have been sent.'],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 202);
    }
}
