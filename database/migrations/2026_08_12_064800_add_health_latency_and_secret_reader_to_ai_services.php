<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_services', function (Blueprint $table) {
            $table->unsignedInteger('last_health_latency_ms')->nullable()
                ->after('last_health_checked_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION app.ai_service_encrypted_key(p_ai_service_id uuid)
                RETURNS text
                LANGUAGE sql
                SECURITY DEFINER
                STABLE
                SET search_path = app, pg_catalog
                AS $$
                    SELECT encrypted_api_key
                    FROM app.ai_services
                    WHERE ai_service_id = p_ai_service_id
                $$;

                REVOKE ALL ON FUNCTION app.ai_service_encrypted_key(uuid) FROM PUBLIC;
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS app.ai_service_encrypted_key(uuid)');
        }

        Schema::table('ai_services', function (Blueprint $table) {
            $table->dropColumn('last_health_latency_ms');
        });
    }
};
