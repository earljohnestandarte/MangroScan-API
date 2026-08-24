<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exported_files', function (Blueprint $table) {
            $table->uuid('export_file_id')->primary();
            $table->uuid('report_id')->nullable();
            $table->uuid('mission_id')->nullable();
            $table->string('export_type', 50);
            $table->string('file_name', 255);
            $table->text('file_path')->unique();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->uuid('exported_by');
            $table->timestampTz('exported_at')->useCurrent();

            $table->foreign('report_id')->references('report_id')->on('reports')->restrictOnDelete();
            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->restrictOnDelete();
            $table->foreign('exported_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->index(['report_id', 'exported_at']);
            $table->index(['mission_id', 'export_type', 'exported_at']);
        });

        Schema::create('export_jobs', function (Blueprint $table) {
            $table->uuid('export_job_id')->primary();
            $table->uuid('organization_id');
            $table->uuid('report_id');
            $table->uuid('mission_id');
            $table->string('export_type', 50);
            $table->jsonb('filters')->nullable();
            $table->jsonb('options')->nullable();
            $table->string('job_status', 30)->default('queued');
            $table->uuid('exported_file_id')->nullable()->unique();
            $table->uuid('created_by');
            $table->string('idempotency_key', 100);
            $table->string('request_fingerprint', 64);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();

            $table->foreign('organization_id')->references('organization_id')->on('organizations')->restrictOnDelete();
            $table->foreign('report_id')->references('report_id')->on('reports')->restrictOnDelete();
            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->restrictOnDelete();
            $table->foreign('exported_file_id')->references('export_file_id')->on('exported_files')->restrictOnDelete();
            $table->foreign('created_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->unique(['created_by', 'idempotency_key']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['job_status', 'created_at']);
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $table = DB::getDriverName() === 'pgsql' ? 'app.export_jobs' : 'export_jobs';
            DB::statement(<<<SQL
                CREATE UNIQUE INDEX export_jobs_one_active_type_per_report
                ON {$table} (report_id, export_type)
                WHERE job_status IN ('queued', 'running')
                SQL);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE exported_files
                ADD CONSTRAINT exported_files_type_check
                    CHECK (export_type IN ('csv', 'xlsx', 'geojson', 'kml'));

                ALTER TABLE export_jobs
                ADD CONSTRAINT export_jobs_type_check
                    CHECK (export_type IN ('csv', 'xlsx', 'geojson', 'kml')),
                ADD CONSTRAINT export_jobs_status_check
                    CHECK (job_status IN ('queued', 'running', 'completed', 'failed')),
                ADD CONSTRAINT export_jobs_completion_check
                    CHECK (
                        (job_status = 'completed' AND exported_file_id IS NOT NULL AND completed_at IS NOT NULL AND error_message IS NULL)
                        OR (job_status <> 'completed' AND exported_file_id IS NULL AND completed_at IS NULL)
                    );

                CREATE TRIGGER trg_export_jobs_touch_updated_at
                BEFORE UPDATE ON app.export_jobs
                FOR EACH ROW
                EXECUTE FUNCTION app.fn_touch_updated_at()
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('exported_files');
    }
};
