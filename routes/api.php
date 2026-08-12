<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedProfileController;
use App\Http\Controllers\Api\V1\Auth\EffectivePermissionsController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Drone\DroneIndexController;
use App\Http\Controllers\Api\V1\Flight\MissionFlightIndexController;
use App\Http\Controllers\Api\V1\Flight\MissionFlightStoreController;
use App\Http\Controllers\Api\V1\Mission\MissionApprovalController;
use App\Http\Controllers\Api\V1\Mission\MissionIndexController;
use App\Http\Controllers\Api\V1\Mission\MissionShowController;
use App\Http\Controllers\Api\V1\Mission\MissionStoreController;
use App\Http\Controllers\Api\V1\Mission\MissionTeamReplaceController;
use App\Http\Controllers\Api\V1\Mission\MissionUpdateController;
use App\Http\Controllers\Api\V1\Platform\HealthController;
use App\Http\Controllers\Api\V1\Platform\MetaCapabilitiesController;
use App\Http\Controllers\Api\V1\Rbac\PermissionIndexController;
use App\Http\Controllers\Api\V1\Rbac\RoleIndexController;
use App\Http\Controllers\Api\V1\Rbac\UserRoleReplaceController;
use App\Http\Controllers\Api\V1\Site\SiteBoundaryIndexController;
use App\Http\Controllers\Api\V1\Site\SiteBoundaryStoreController;
use App\Http\Controllers\Api\V1\Site\SiteIndexController;
use App\Http\Controllers\Api\V1\Site\SiteShowController;
use App\Http\Controllers\Api\V1\Site\SiteStoreController;
use App\Http\Controllers\Api\V1\User\UserIndexController;
use App\Http\Controllers\Api\V1\User\UserShowController;
use App\Http\Controllers\Api\V1\User\UserStoreController;
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

    // [USR-01] GET /api/v1/users
    Route::get('/users', UserIndexController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'permission:users.manage',
        'throttle:auth.authenticated',
    ]);

    // [USR-02] POST /api/v1/users
    Route::post('/users', UserStoreController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'permission:users.manage',
        'throttle:auth.authenticated',
    ]);

    // [USR-03] GET /api/v1/users/{id}
    Route::get('/users/{user}', UserShowController::class)
        ->whereUuid('user')
        ->middleware([
            'auth:sanctum',
            EnsureActiveIdentity::class,
            'permission:users.manage',
            'throttle:auth.authenticated',
        ]);

    // [RBAC-03] PUT /api/v1/users/{id}/roles
    Route::put('/users/{user}/roles', UserRoleReplaceController::class)
        ->whereUuid('user')
        ->middleware([
            'auth:sanctum',
            EnsureActiveIdentity::class,
            'permission:roles.manage',
            'throttle:auth.authenticated',
        ]);

    // [SITE-01] GET /api/v1/sites
    Route::get('/sites', SiteIndexController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'permission:sites.read',
        'throttle:auth.authenticated',
    ]);

    // [SITE-02] POST /api/v1/sites
    Route::post('/sites', SiteStoreController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'permission:sites.manage',
        'throttle:auth.authenticated',
    ]);

    // [SITE-03] GET /api/v1/sites/{id}
    Route::get('/sites/{site}', SiteShowController::class)
        ->whereUuid('site')
        ->middleware([
            'auth:sanctum',
            EnsureActiveIdentity::class,
            'permission:sites.read',
            'throttle:auth.authenticated',
        ]);

    // [BOUND-01] GET /api/v1/sites/{id}/boundaries
    Route::get('/sites/{site}/boundaries', SiteBoundaryIndexController::class)
        ->whereUuid('site')
        ->middleware([
            'auth:sanctum',
            EnsureActiveIdentity::class,
            'permission:sites.read',
            'throttle:auth.authenticated',
        ]);

    // [BOUND-02] POST /api/v1/sites/{id}/boundaries
    Route::post('/sites/{site}/boundaries', SiteBoundaryStoreController::class)
        ->whereUuid('site')
        ->middleware([
            'auth:sanctum',
            EnsureActiveIdentity::class,
            'permission:boundaries.manage',
            'throttle:auth.authenticated',
        ]);

    // [MSN-01] GET /api/v1/missions
    Route::get('/missions', MissionIndexController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'permission:missions.read',
        'throttle:auth.authenticated',
    ]);

    // [MSN-02] POST /api/v1/missions
    Route::post('/missions', MissionStoreController::class)->middleware([
        'auth:sanctum', EnsureActiveIdentity::class,
        'permission:missions.create', 'throttle:auth.authenticated',
    ]);

    // [MSN-03] GET /api/v1/missions/{id}
    Route::get('/missions/{mission}', MissionShowController::class)->whereUuid('mission')->middleware([
        'auth:sanctum', EnsureActiveIdentity::class, 'permission:missions.read', 'throttle:auth.authenticated',
    ]);

    // [MSN-04] PATCH /api/v1/missions/{id}
    Route::patch('/missions/{mission}', MissionUpdateController::class)->whereUuid('mission')->middleware([
        'auth:sanctum', EnsureActiveIdentity::class, 'permission:missions.update', 'throttle:auth.authenticated',
    ]);

    // [TEAM-01] PUT /api/v1/missions/{id}/team
    Route::put('/missions/{mission}/team', MissionTeamReplaceController::class)->whereUuid('mission')->middleware([
        'auth:sanctum', EnsureActiveIdentity::class, 'permission:mission_team.manage', 'throttle:auth.authenticated',
    ]);

    // [MSN-06] POST /api/v1/missions/{id}/approve
    Route::post('/missions/{mission}/approve', MissionApprovalController::class)->whereUuid('mission')->middleware([
        'auth:sanctum', EnsureActiveIdentity::class, 'permission:missions.approve', 'throttle:auth.authenticated',
    ]);

    // [DRONE-01] GET /api/v1/drones
    Route::get('/drones', DroneIndexController::class)->middleware([
        'auth:sanctum', EnsureActiveIdentity::class, 'throttle:auth.authenticated',
    ]);

    // [FLT-01] GET /api/v1/missions/{id}/flights
    Route::get('/missions/{mission}/flights', MissionFlightIndexController::class)
        ->whereUuid('mission')
        ->middleware([
            'auth:sanctum', EnsureActiveIdentity::class,
            'permission:flights.read', 'throttle:auth.authenticated',
        ]);

    // [FLT-02] POST /api/v1/missions/{id}/flights
    Route::post('/missions/{mission}/flights', MissionFlightStoreController::class)
        ->whereUuid('mission')
        ->middleware([
            'auth:sanctum', EnsureActiveIdentity::class,
            'permission:flights.create', 'throttle:auth.authenticated',
        ]);
});
