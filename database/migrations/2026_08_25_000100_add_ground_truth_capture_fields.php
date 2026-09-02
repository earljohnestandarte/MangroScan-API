<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ground_truth_tree_records', function (Blueprint $table) {
            $table->string('field_code', 80)->nullable()->after('validation_session_id');
            $table->decimal('crown_diameter_m', 8, 2)->nullable()->after('diameter_cm');
            $table->boolean('is_tree')->default(true)->after('health_status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE ground_truth_tree_records
                ADD CONSTRAINT ground_truth_tree_records_crown_diameter_check
                CHECK (crown_diameter_m IS NULL OR crown_diameter_m >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ground_truth_tree_records DROP CONSTRAINT ground_truth_tree_records_crown_diameter_check');
        }

        Schema::table('ground_truth_tree_records', function (Blueprint $table) {
            $table->dropColumn(['field_code', 'crown_diameter_m', 'is_tree']);
        });
    }
};
