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
            $table->uuid('requested_by_user_id')->nullable();
            $table->string('job_type', 40);
            $table->string('job_status', 30)->default('queued');
            $table->jsonb('parameters')->nullable();
            $table->unsignedSmallInteger('progress_percent')->default(0);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('queued_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('output_summary')->nullable();
            $table->timestampsTz();

            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->restrictOnDelete();
            $table->foreign('flight_session_id')->references('flight_session_id')->on('flight_sessions')->restrictOnDelete();
            $table->foreign('requested_by_user_id')->references('user_id')->on('users')->restrictOnDelete();

            $table->index(['mission_id', 'job_status']);
            $table->index(['flight_session_id', 'job_status']);
            $table->index(['job_status', 'queued_at']);
            $table->index(['job_type', 'queued_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE processing_jobs
                ADD CONSTRAINT processing_jobs_type_check
                    CHECK (job_type IN ('tree_detection', 'species_classification', 'full_pipeline')),
                ADD CONSTRAINT processing_jobs_status_check
                    CHECK (job_status IN ('queued', 'running', 'succeeded', 'failed', 'cancelled')),
                ADD CONSTRAINT processing_jobs_progress_check
                    CHECK (progress_percent BETWEEN 0 AND 100),
                ADD CONSTRAINT processing_jobs_timestamps_check
                    CHECK (
                        (started_at IS NULL OR started_at >= queued_at)
                        AND (completed_at IS NULL OR (started_at IS NOT NULL AND completed_at >= started_at))
                    )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_jobs');
    }
};
