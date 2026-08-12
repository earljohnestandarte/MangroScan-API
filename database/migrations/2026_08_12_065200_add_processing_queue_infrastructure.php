<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processing_jobs', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('created_by');
            $table->string('request_fingerprint', 64)->nullable()->after('idempotency_key');
            $table->unique(['created_by', 'idempotency_key']);
        });

        Schema::create('model_runs', function (Blueprint $table) {
            $table->uuid('model_run_id')->primary();
            $table->uuid('processing_job_id');
            $table->uuid('model_version_id');
            $table->string('run_type', 80);
            $table->uuid('input_media_id')->nullable();
            $table->jsonb('parameters')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('run_status', 30)->default('queued');
            $table->timestampTz('created_at');

            $table->foreign('processing_job_id')->references('processing_job_id')->on('processing_jobs')->cascadeOnDelete();
            $table->foreign('model_version_id')->references('model_version_id')->on('ai_model_versions')->restrictOnDelete();
            $table->foreign('input_media_id')->references('media_asset_id')->on('media_assets')->restrictOnDelete();

            $table->index('processing_job_id');
            $table->index(['input_media_id', 'run_status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE model_runs
                ADD CONSTRAINT model_runs_type_check
                    CHECK (run_type IN ('tree_detection', 'species_classification')),
                ADD CONSTRAINT model_runs_status_check
                    CHECK (run_status IN ('queued', 'running', 'completed', 'failed')),
                ADD CONSTRAINT model_runs_timestamps_check
                    CHECK (completed_at IS NULL OR (started_at IS NOT NULL AND completed_at >= started_at))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('model_runs');

        Schema::table('processing_jobs', function (Blueprint $table) {
            $table->dropUnique(['created_by', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'request_fingerprint']);
        });
    }
};
