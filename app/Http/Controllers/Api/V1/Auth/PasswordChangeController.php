<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordChangeRequest;
use App\Models\User;
use App\Services\Auth\PasswordChangeService;
use Illuminate\Http\Response;

class PasswordChangeController extends Controller
{
    // [AUTH-05] Change the authenticated user's password and revoke active credentials.
    public function __invoke(PasswordChangeRequest $request, PasswordChangeService $passwords): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $passwords->change(
            $actor,
            $validated['current_password'],
            $validated['new_password'],
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->noContent();
    }
}
