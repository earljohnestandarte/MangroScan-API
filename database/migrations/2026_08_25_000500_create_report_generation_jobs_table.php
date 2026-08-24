<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_generation_jobs', function (Blueprint $table) {
            $table->uuid('report_generation_job_id')->primary();
            $table->uuid('organization_id');
            $table->uuid('report_id');
            $table->string('format', 20);
            $table->jsonb('options')->nullable();
            $table->string('job_status', 30)->default('queued');
            $table->string('file_name', 255)->nullable();
            $table->string('storage_key', 1024)->nullable()->unique();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->uuid('created_by');
            $table->string('idempotency_key', 100);
            $table->string('request_fingerprint', 64);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();

            $table->foreign('organization_id')->references('organization_id')->on('organizations')->restrictOnDelete();
            $table->foreign('report_id')->references('report_id')->on('reports')->restrictOnDelete();
            $table->foreign('created_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->unique(['created_by', 'idempotency_key']);
            $table->index(['report_id', 'job_status']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['job_status', 'created_at']);
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $table = DB::getDriverName() === 'pgsql' ? 'app.report_generation_jobs' : 'report_generation_jobs';
            DB::statement(<<<SQL
                CREATE UNIQUE INDEX report_generation_jobs_one_active_per_report
                ON {$table} (report_id)
                WHERE job_status IN ('queued', 'running')
                SQL);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE report_generation_jobs
                ADD CONSTRAINT report_generation_jobs_format_check
                    CHECK (format = 'pdf'),
                ADD CONSTRAINT report_generation_jobs_status_check
                    CHECK (job_status IN ('queued', 'running', 'completed', 'failed')),
                ADD CONSTRAINT report_generation_jobs_artifact_check
                    CHECK (
                        (job_status = 'completed'
                            AND file_name IS NOT NULL
                            AND storage_key IS NOT NULL
                            AND file_size_bytes IS NOT NULL
                            AND checksum_sha256 IS NOT NULL
                            AND started_at IS NOT NULL
                            AND completed_at IS NOT NULL
                            AND error_message IS NULL)
                        OR
                        (job_status <> 'completed'
                            AND completed_at IS NULL
                            AND file_name IS NULL
                            AND storage_key IS NULL
                            AND file_size_bytes IS NULL
                            AND checksum_sha256 IS NULL)
                    );

                CREATE TRIGGER trg_report_generation_jobs_touch_updated_at
                BEFORE UPDATE ON app.report_generation_jobs
                FOR EACH ROW
                EXECUTE FUNCTION app.fn_touch_updated_at()
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_generation_jobs');
    }
};
