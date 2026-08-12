<?php

use App\Http\Controllers\Api\V1\Platform\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // [SYS-01] GET /api/v1/health
    Route::get('/health', HealthController::class);
});
