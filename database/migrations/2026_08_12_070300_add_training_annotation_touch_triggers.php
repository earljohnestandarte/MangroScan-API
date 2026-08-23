<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const UPDATED_AT_TABLES = [
        'annotation_items',
        'annotation_projects',
        'training_dataset_items',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::UPDATED_AT_TABLES as $table) {
            $trigger = "trg_{$table}_touch_updated_at";

            DB::unprepared(<<<SQL
                DROP TRIGGER IF EXISTS {$trigger} ON app.{$table};

                CREATE TRIGGER {$trigger}
                BEFORE UPDATE ON app.{$table}
                FOR EACH ROW
                EXECUTE FUNCTION app.fn_touch_updated_at();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse(self::UPDATED_AT_TABLES) as $table) {
            $trigger = "trg_{$table}_touch_updated_at";
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger} ON app.{$table}");
        }
    }
};
