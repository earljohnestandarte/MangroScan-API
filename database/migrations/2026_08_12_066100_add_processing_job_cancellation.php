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
            $table->timestampTz('cancelled_at')->nullable()->after('completed_at');
            $table->uuid('cancelled_by')->nullable()->after('cancelled_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            $table->foreign('cancelled_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->index(['job_status', 'cancelled_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE processing_jobs DROP CONSTRAINT processing_jobs_status_check');
            DB::statement("ALTER TABLE processing_jobs ADD CONSTRAINT processing_jobs_status_check CHECK (job_status IN ('queued', 'running', 'completed', 'failed', 'cancelled'))");
            DB::statement('ALTER TABLE model_runs DROP CONSTRAINT model_runs_status_check');
            DB::statement("ALTER TABLE model_runs ADD CONSTRAINT model_runs_status_check CHECK (run_status IN ('queued', 'running', 'completed', 'failed', 'cancelled'))");
            DB::statement(<<<'SQL'
                ALTER TABLE processing_jobs
                ADD CONSTRAINT processing_jobs_cancellation_check
                CHECK (
                    (job_status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL)
                    OR (job_status <> 'cancelled' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
                )
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE processing_jobs DROP CONSTRAINT processing_jobs_cancellation_check');
            DB::table('processing_jobs')->where('job_status', 'cancelled')->update([
                'job_status' => 'failed',
                'error_message' => 'Cancelled before cancellation-schema rollback.',
                'updated_at' => now('UTC'),
            ]);
            DB::table('model_runs')->where('run_status', 'cancelled')->update(['run_status' => 'failed']);
            DB::statement('ALTER TABLE processing_jobs DROP CONSTRAINT processing_jobs_status_check');
            DB::statement("ALTER TABLE processing_jobs ADD CONSTRAINT processing_jobs_status_check CHECK (job_status IN ('queued', 'running', 'completed', 'failed'))");
            DB::statement('ALTER TABLE model_runs DROP CONSTRAINT model_runs_status_check');
            DB::statement("ALTER TABLE model_runs ADD CONSTRAINT model_runs_status_check CHECK (run_status IN ('queued', 'running', 'completed', 'failed'))");
        }

        Schema::table('processing_jobs', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropIndex(['job_status', 'cancelled_at']);
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });
    }
};
