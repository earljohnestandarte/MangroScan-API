<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE VIEW v_user_effective_permissions AS
            SELECT
                u.user_id,
                u.organization_id,
                r.role_id,
                r.role_name,
                p.permission_id,
                p.permission_code
            FROM users AS u
            INNER JOIN user_roles AS ur ON ur.user_id = u.user_id
            INNER JOIN roles AS r ON r.role_id = ur.role_id
                AND (r.organization_id IS NULL OR r.organization_id = u.organization_id)
            INNER JOIN role_permissions AS rp ON rp.role_id = r.role_id
            INNER JOIN permissions AS p ON p.permission_id = rp.permission_id
            WHERE u.status = 'active'
                AND u.deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS v_user_effective_permissions');
    }
};
