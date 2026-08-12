<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveIdentity
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $user->loadMissing('organization');

        if ($user->isActive() && $user->organization?->status === 'active') {
            return $next($request);
        }

        return new JsonResponse([
            'error' => [
                'code' => 'ACCOUNT_INACTIVE',
                'message' => 'The authenticated account is not active.',
                'details' => (object) [],
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], 403);
    }
}
