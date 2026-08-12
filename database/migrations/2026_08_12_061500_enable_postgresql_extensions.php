<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('CREATE EXTENSION IF NOT EXISTS "pgcrypto"');
        DB::unprepared('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function down(): void
    {
        // Intentionally irreversible: dropping either extension can destroy
        // database-wide UUID or spatial dependencies owned by other objects.
    }
};
