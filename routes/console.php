<?php

use App\Services\Dashboard\DashboardReadModelRefresher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('dashboard:refresh', function (DashboardReadModelRefresher $refresher) {
    if ($refresher->refresh()) {
        $this->info('Dashboard mission metrics refreshed.');
    } else {
        $this->info('Dashboard mission metrics use a live SQLite compatibility view; no refresh was needed.');
    }
})->purpose('Refresh the PostgreSQL dashboard mission-metrics snapshot');
