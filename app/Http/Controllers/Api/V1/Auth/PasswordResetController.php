<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordResetRequest;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\Response;

class PasswordResetController extends Controller
{
    // [AUTH-07] Complete a one-time password reset and revoke active credentials.
    public function __invoke(PasswordResetRequest $request, PasswordResetService $resets): Response
    {
        $validated = $request->validated();
        $resets->reset(
            $validated['email'], $validated['token'], $validated['password'],
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->noContent();
    }
}
