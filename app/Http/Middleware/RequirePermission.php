<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\EffectiveAccessService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function __construct(private readonly EffectiveAccessService $effectiveAccess) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && in_array(
                $permission,
                $this->effectiveAccess->rolesAndPermissions($user)['permissions'],
                true,
            )) {
            return $next($request);
        }

        return new JsonResponse([
            'error' => [
                'code' => 'FORBIDDEN',
                'message' => 'You do not have permission to perform this action.',
                'details' => [
                    'required_permission' => $permission,
                ],
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], 403);
    }
}
