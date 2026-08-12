<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->uuid('report_id')->primary();
            $table->uuid('mission_id');
            $table->uuid('site_id');
            $table->string('report_title', 200);
            $table->string('report_type', 80);
            $table->string('report_status', 30)->default('draft');
            $table->uuid('generated_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->text('summary')->nullable();
            $table->timestampsTz();

            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->restrictOnDelete();
            $table->foreign('site_id')->references('site_id')->on('survey_sites')->restrictOnDelete();
            $table->foreign('generated_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by')->references('user_id')->on('users')->restrictOnDelete();

            $table->index(['mission_id', 'report_status']);
            $table->index(['site_id', 'report_status']);
            $table->index(['report_type', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE reports
                ADD CONSTRAINT reports_type_check
                    CHECK (report_type IN ('monitoring_summary', 'validation_report', 'species_report')),
                ADD CONSTRAINT reports_status_check
                    CHECK (report_status IN ('draft', 'generated', 'approved', 'archived'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
