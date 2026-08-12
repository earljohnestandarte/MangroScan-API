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

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION app.fn_user_has_permission(p_user_id uuid, p_permission_code text)
            RETURNS boolean
            LANGUAGE sql
            STABLE
            PARALLEL SAFE
            SET search_path = app, pg_catalog
            AS $$
                SELECT EXISTS (
                    SELECT 1
                    FROM app.v_user_effective_permissions AS access
                    WHERE access.user_id = p_user_id
                        AND access.permission_code = p_permission_code
                )
            $$;

            REVOKE ALL ON FUNCTION app.fn_user_has_permission(uuid, text) FROM PUBLIC;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS app.fn_user_has_permission(uuid, text)');
        }
    }
};
