<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_jobs', function (Blueprint $table) {
            $table->uuid('processing_job_id')->primary();
            $table->uuid('mission_id');
            $table->uuid('flight_session_id')->nullable();
            $table->string('job_type', 80);
            $table->string('job_status', 30)->default('queued');
            $table->jsonb('input_summary')->nullable();
            $table->jsonb('output_summary')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->restrictOnDelete();
            $table->foreign('flight_session_id')->references('flight_session_id')->on('flight_sessions')->restrictOnDelete();
            $table->foreign('created_by')->references('user_id')->on('users')->restrictOnDelete();

            $table->index(['mission_id', 'job_status']);
            $table->index(['flight_session_id', 'job_status']);
            $table->index(['job_status', 'created_at']);
            $table->index(['job_type', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE processing_jobs
                ADD CONSTRAINT processing_jobs_type_check
                    CHECK (job_type IN ('image_quality', 'detection', 'classification', 'photogrammetry', 'full_pipeline')),
                ADD CONSTRAINT processing_jobs_status_check
                    CHECK (job_status IN ('queued', 'running', 'completed', 'failed')),
                ADD CONSTRAINT processing_jobs_timestamps_check
                    CHECK (
                        completed_at IS NULL OR (started_at IS NOT NULL AND completed_at >= started_at)
                    )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_jobs');
    }
};
