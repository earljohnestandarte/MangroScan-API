<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedProfileController;
use App\Http\Controllers\Api\V1\Auth\EffectivePermissionsController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordChangeController;
use App\Http\Controllers\Api\V1\Auth\PasswordForgotController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Drone\DroneIndexController;
use App\Http\Controllers\Api\V1\Flight\FlightChecklistStoreController;
use App\Http\Controllers\Api\V1\Flight\FlightCompleteController;
use App\Http\Controllers\Api\V1\Flight\FlightShowController;
use App\Http\Controllers\Api\V1\Flight\FlightStartController;
use App\Http\Controllers\Api\V1\Flight\MissionFlightIndexController;
use App\Http\Controllers\Api\V1\Flight\MissionFlightStoreController;
use App\Http\Controllers\Api\V1\Media\FlightMediaIndexController;
use App\Http\Controllers\Api\V1\Mission\MissionApprovalController;
use App\Http\Controllers\Api\V1\Mission\MissionIndexController;
use App\Http\Controllers\Api\V1\Mission\MissionShowController;
use App\Http\Controllers\Api\V1\Mission\MissionStoreController;
use App\Http\Controllers\Api\V1\Mission\MissionTeamReplaceController;
use App\Http\Controllers\Api\V1\Mission\MissionUpdateController;
use App\Http\Controllers\Api\V1\Mobile\MobileBootstrapController;
use App\Http\Controllers\Api\V1\Mobile\MobileMissionBundleController;
use App\Http\Controllers\Api\V1\Mobile\SyncDeviceRegisterController;
use App\Http\Controllers\Api\V1\Organization\OrganizationIndexController;
use App\Http\Controllers\Api\V1\Organization\OrganizationShowController;
use App\Http\Controllers\Api\V1\Organization\OrganizationStoreController;
use App\Http\Controllers\Api\V1\Organization\OrganizationUpdateController;
use App\Http\Controllers\Api\V1\Platform\HealthController;
use App\Http\Controllers\Api\V1\Platform\MetaCapabilitiesController;
use App\Http\Controllers\Api\V1\Processing\ProcessingJobIndexController;
use App\Http\Controllers\Api\V1\Rbac\PermissionIndexController;
use App\Http\Controllers\Api\V1\Rbac\RoleIndexController;
use App\Http\Controllers\Api\V1\Rbac\RolePermissionReplaceController;
use App\Http\Controllers\Api\V1\Rbac\UserRoleReplaceController;
use App\Http\Controllers\Api\V1\Site\SiteBoundaryIndexController;
use App\Http\Controllers\Api\V1\Site\SiteBoundaryStoreController;
use App\Http\Controllers\Api\V1\Site\SiteIndexController;
use App\Http\Controllers\Api\V1\Site\SitePlotIndexController;
use App\Http\Controllers\Api\V1\Site\SitePlotStoreController;
use App\Http\Controllers\Api\V1\Site\SiteShowController;
use App\Http\Controllers\Api\V1\Site\SiteStoreController;
use App\Http\Controllers\Api\V1\Site\SiteUpdateController;
use App\Http\Controllers\Api\V1\User\UserActivationController;
use App\Http\Controllers\Api\V1\User\UserIndexController;
use App\Http\Controllers\Api\V1\User\UserShowController;
use App\Http\Controllers\Api\V1\User\UserStoreController;
use App\Http\Controllers\Api\V1\User\UserUpdateController;
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

    // [AUTH-05] PUT /api/v1/auth/password
    Route::put('/auth/password', PasswordChangeController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'throttle:auth.authenticated',
    ]);

    // [AUTH-06] POST /api/v1/auth/password/forgot
    Route::post('/auth/password/forgot', PasswordForgotController::class)
        ->middleware('throttle:auth.login');

    // [AUTH-07] POST /api/v1/auth/password/reset
    Route::post('/auth/password/reset', PasswordResetController::class)
        ->middleware('throttle:auth.login');

    // [AUTH-08] GET /api/v1/auth/permissions
    Route::get('/auth/permissions', EffectivePermissionsController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'throttle:auth.authenticated',
    ]);

    // [ORG-01] GET /api/v1/organizations
    Route::get('/organizations', OrganizationIndexController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'permission:organizations.manage',
        'throttle:auth.authenticated',
    ]);

    // [ORG-02] POST /api/v1/organizations
    Route::post('/organizations', OrganizationStoreController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'permission:organizations.manage',
        'throttle:auth.authenticated',
    ]);

    // [ORG-03] GET /api/v1/organizations/{id}
    Route::get('/organizations/{organization}', OrganizationShowController::class)
        ->whereUuid('organization')
        ->middleware([
            'auth:sanctum',
            EnsureActiveIdentity::class,
            'permission:organizations.manage',
            'throttle:auth.authenticated',
        ]);

    // [ORG-04] PATCH /api/v1/organizations/{id}
    Route::patch('/organizations/{organization}', OrganizationUpdateController::class)
        ->whereUuid('organization')
        ->middleware([
            'auth:sanctum',
            EnsureActiveIdentity::class,
            'permission:organizations.manage',
            'throttle:auth.authenticated',
        ]);

    // [SYNC-01] POST /api/v1/mobile/devices/register
    Route::post('/mobile/devices/register', SyncDeviceRegisterController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'throttle:auth.authenticated',
    ]);

    // [SYNC-02] GET /api/v1/mobile/bootstrap
    Route::get('/mobile/bootstrap', MobileBootstrapController::class)->middleware([
        'auth:sanctum',
        EnsureActiveIdentity::class,
        'permission:missions.read',
        'permission:flights.read',
        'throttle:auth.authenticated',
    ]);

    // [SYNC-03] GET /api/v1/mobile/missions/{id}/bundle
    Route::get('/mobile/missions/{mission}/bundle', MobileMissionBundleController::class)
        ->whereUuid('mission')
        ->middleware([
            'auth:sanctum',
            EnsureActiveIdentity::class,
            'permission:missions.read',
            'permission:flights.read',
            'permission:sites.read',
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

    // [RBAC-04] PUT /api/v1/roles/{id}/permissions
    Route::put('/roles/{role}/permissions', RolePermissionReplaceController::class)
        ->whereUuid('role')
        ->middleware([
            'auth:sanctum', EnsureActiveIdentity::class,
            'permission:roles.manage', 'permission:permissions.manage',
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

    // [USR-04] PATCH /api/v1/users/{id}
    Route::patch('/users/{user}', UserUpdateController::class)
        ->whereUuid('user')
        ->middleware([
            'auth:sanctum',
            EnsureActiveIdentity::class,
            'permission:users.manage',
            'throttle:auth.authenticated',
        ]);

    // [USR-05] POST /api/v1/users/{id}/activation
    Route::post('/users/{user}/activation', UserActivationController::class)
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

    // [SITE-04] PATCH /api/v1/sites/{id}
    Route::patch('/sites/{site}', SiteUpdateController::class)->whereUuid('site')->middleware([
        'auth:sanctum', EnsureActiveIdentity::class,
        'permission:sites.manage', 'throttle:auth.authenticated',
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

    // [PLOT-01] GET /api/v1/sites/{id}/plots
    Route::get('/sites/{site}/plots', SitePlotIndexController::class)
        ->whereUuid('site')
        ->middleware([
            'auth:sanctum', EnsureActiveIdentity::class,
            'permission:sites.read', 'throttle:auth.authenticated',
        ]);

    // [PLOT-02] POST /api/v1/sites/{id}/plots
    Route::post('/sites/{site}/plots', SitePlotStoreController::class)
        ->whereUuid('site')
        ->middleware([
            'auth:sanctum', EnsureActiveIdentity::class,
            'permission:plots.manage', 'throttle:auth.authenticated',
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

    // [FLT-03] GET /api/v1/flights/{id}
    Route::get('/flights/{flight}', FlightShowController::class)
        ->whereUuid('flight')
        ->middleware([
            'auth:sanctum', EnsureActiveIdentity::class,
            'permission:flights.read', 'throttle:auth.authenticated',
        ]);

    // [CHK-01] POST /api/v1/flights/{id}/checklists
    Route::post('/flights/{flight}/checklists', FlightChecklistStoreController::class)
        ->whereUuid('flight')
        ->middleware([
            'auth:sanctum', EnsureActiveIdentity::class,
            'permission:checklists.submit', 'throttle:auth.authenticated',
        ]);

    // [FLT-05] POST /api/v1/flights/{id}/start
    Route::post('/flights/{flight}/start', FlightStartController::class)
        ->whereUuid('flight')
        ->middleware([
            'auth:sanctum', EnsureActiveIdentity::class,
            'permission:flights.start', 'throttle:auth.authenticated',
        ]);

    // [FLT-06] POST /api/v1/flights/{id}/complete
    Route::post('/flights/{flight}/complete', FlightCompleteController::class)
        ->whereUuid('flight')
        ->middleware([
            'auth:sanctum', EnsureActiveIdentity::class,
            'permission:flights.complete', 'throttle:auth.authenticated',
        ]);

    // [MEDIA-01] GET /api/v1/flights/{id}/media
    Route::get('/flights/{flight}/media', FlightMediaIndexController::class)
        ->whereUuid('flight')
        ->middleware([
            'auth:sanctum', EnsureActiveIdentity::class,
            'permission:media.read', 'throttle:auth.authenticated',
        ]);

    // [JOB-01] GET /api/v1/processing-jobs
    Route::get('/processing-jobs', ProcessingJobIndexController::class)->middleware([
        'auth:sanctum', EnsureActiveIdentity::class,
        'permission:processing_jobs.manage', 'throttle:auth.authenticated',
    ]);
});
