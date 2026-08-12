<?php

use App\Http\Controllers\Api\V1\Platform\HealthController;
use App\Http\Controllers\Api\V1\Platform\MetaCapabilitiesController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // [SYS-01] GET /api/v1/health
    Route::get('/health', HealthController::class);

    // [SYS-02] GET /api/v1/meta/capabilities
    Route::get('/meta/capabilities', MetaCapabilitiesController::class);
});
