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
            DROP TRIGGER IF EXISTS trg_batteries_touch_updated_at ON app.batteries;

            CREATE TRIGGER trg_batteries_touch_updated_at
            BEFORE UPDATE ON app.batteries
            FOR EACH ROW
            EXECUTE FUNCTION app.fn_touch_updated_at();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_batteries_touch_updated_at ON app.batteries');
    }
};
