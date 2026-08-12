<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\LogoutService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LogoutController extends Controller
{
    /**
     * [AUTH-03] Revoke only the Bearer token used for this request.
     *
     * @throws AuthenticationException
     */
    public function __invoke(Request $request, LogoutService $logout): Response
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        $logout->revoke(
            user: $user,
            token: $token,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id'),
        );

        return response()->noContent();
    }
}
