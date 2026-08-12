<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_missions', function (Blueprint $table) {
            $table->uuid('mission_id')->primary();
            $table->uuid('site_id');
            $table->string('mission_code', 50)->unique();
            $table->string('mission_title', 150);
            $table->text('mission_objective');
            $table->timestampTz('planned_start_at')->nullable();
            $table->timestampTz('planned_end_at')->nullable();
            $table->timestampTz('actual_start_at')->nullable();
            $table->timestampTz('actual_end_at')->nullable();
            $table->string('mission_status', 30)->default('planned');
            $table->decimal('coverage_target_hectares', 12, 4)->nullable();
            $table->decimal('coverage_completed_hectares', 12, 4)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('site_id')
                ->references('site_id')
                ->on('survey_sites')
                ->restrictOnDelete();
            $table->foreign('created_by')
                ->references('user_id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('approved_by')
                ->references('user_id')
                ->on('users')
                ->restrictOnDelete();

            $table->index(['site_id', 'mission_status']);
            $table->index(['site_id', 'planned_start_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE survey_missions
                ADD CONSTRAINT survey_missions_status_check
                CHECK (mission_status IN ('planned', 'in_progress', 'completed', 'cancelled', 'failed'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_missions');
    }
};
