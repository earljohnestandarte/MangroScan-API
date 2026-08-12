<?php

use App\Exceptions\WorkflowConflictException;
use App\Http\Middleware\AddRequestId;
use App\Http\Middleware\RequirePermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->appendToGroup('api', AddRequestId::class);
        $middleware->alias([
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (BadRequestHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $requestId = AddRequestId::resolve($request);

            return response()->json(['error' => [
                'code' => 'BAD_REQUEST', 'message' => $exception->getMessage(),
                'details' => (object) [], 'request_id' => $requestId,
            ]], 400, ['X-Request-ID' => $requestId]);
        });

        $exceptions->render(function (WorkflowConflictException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $requestId = AddRequestId::resolve($request);

            return response()->json(['error' => [
                'code' => 'CONFLICT', 'message' => $exception->getMessage(),
                'details' => $exception->details, 'request_id' => $requestId,
            ]], 409, ['X-Request-ID' => $requestId]);
        });
        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $requestId = AddRequestId::resolve($request);

            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to perform this action.',
                    'details' => (object) [],
                    'request_id' => $requestId,
                ],
            ], 403, ['X-Request-ID' => $requestId]);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $requestId = AddRequestId::resolve($request);

            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication is required.',
                    'details' => (object) [],
                    'request_id' => $requestId,
                ],
            ], 401, ['X-Request-ID' => $requestId]);
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $requestId = AddRequestId::resolve($request);

            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'The requested resource was not found.',
                    'details' => (object) [],
                    'request_id' => $requestId,
                ],
            ], 404, ['X-Request-ID' => $requestId]);
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $requestId = AddRequestId::resolve($request);

            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'The request contains invalid fields.',
                    'details' => $exception->errors(),
                    'request_id' => $requestId,
                ],
            ], 422, ['X-Request-ID' => $requestId]);
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $requestId = AddRequestId::resolve($request);

            return response()->json([
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'Too many requests.',
                    'details' => (object) [],
                    'request_id' => $requestId,
                ],
            ], 429, [
                ...$exception->getHeaders(),
                'X-Request-ID' => $requestId,
            ]);
        });
    })->create();
