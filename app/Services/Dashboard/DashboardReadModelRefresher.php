<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

class DashboardReadModelRefresher
{
    /**
     * Refresh the PostgreSQL dashboard snapshot.
     *
     * SQLite exposes the same relation as a live compatibility view, so it
     * requires no refresh. Calls made inside a transaction automatically use
     * PostgreSQL's non-concurrent refresh mode.
     */
    public function refresh(bool $concurrently = true): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        $modifier = $concurrently && DB::transactionLevel() === 0 ? ' CONCURRENTLY' : '';
        DB::statement("REFRESH MATERIALIZED VIEW{$modifier} mv_dashboard_mission_metrics");

        return true;
    }
}
