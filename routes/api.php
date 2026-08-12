<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedProfileController;
use App\Http\Controllers\Api\V1\Auth\EffectivePermissionsController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Platform\HealthController;
use App\Http\Controllers\Api\V1\Platform\MetaCapabilitiesController;
use App\Http\Controllers\Api\V1\Rbac\PermissionIndexController;
use App\Http\Controllers\Api\V1\Rbac\RoleIndexController;
use App\Http\Middleware\EnsureActiveIdentity;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // [SYS-01] GET /api/v1/health
    Route::get('/health', HealthController::class);

    // [SYS-02] GET /api/v1/meta/capabilities
    Route::get('/meta/capabilities', MetaCapabilitiesController::class);

    // [AUTH-01] POST /api/v1/auth/login
    Route::post('/auth/login', LoginController::class)->middleware('throttle:auth.login');

    // [AUTH-02] GET /api/v1/auth/me
    Route::get('/auth/me', AuthenticatedProfileController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'throttle:auth.authenticated',
    ]);

    // [AUTH-03] POST /api/v1/auth/logout
    Route::post('/auth/logout', LogoutController::class)->middleware([
        'auth:sanctum',
        'throttle:auth.authenticated',
    ]);

    // [AUTH-08] GET /api/v1/auth/permissions
    Route::get('/auth/permissions', EffectivePermissionsController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'throttle:auth.authenticated',
    ]);

    // [RBAC-01] GET /api/v1/roles
    Route::get('/roles', RoleIndexController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'permission:roles.manage',
        'throttle:auth.authenticated',
    ]);

    // [RBAC-02] GET /api/v1/permissions
    Route::get('/permissions', PermissionIndexController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'permission:permissions.manage',
        'throttle:auth.authenticated',
    ]);
});
