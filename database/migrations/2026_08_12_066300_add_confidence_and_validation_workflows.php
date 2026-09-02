<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_sessions', function (Blueprint $table) {
            $table->string('status', 30)->default('open')->after('method');
            $table->timestampTz('completed_at')->nullable()->after('notes');
            $table->uuid('completed_by')->nullable()->after('completed_at');
            $table->foreign('completed_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->index(['status', 'validation_date']);
        });

        Schema::table('accuracy_metrics', function (Blueprint $table) {
            $table->uuid('validation_session_id')->nullable()->after('accuracy_metric_id');
            $table->foreign('validation_session_id')->references('validation_session_id')->on('validation_sessions')->cascadeOnDelete();
            $table->unique(['validation_session_id', 'metric_type']);
        });

        Schema::create('confidence_flags', function (Blueprint $table) {
            $table->uuid('confidence_flag_id')->primary();
            $table->uuid('mission_id');
            $table->uuid('result_id');
            $table->string('result_type', 30);
            $table->string('status', 30)->default('open');
            $table->string('severity', 20);
            $table->text('review_note')->nullable();
            $table->uuid('assigned_to')->nullable();
            $table->text('reason')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->uuid('created_by');
            $table->timestampsTz();

            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->cascadeOnDelete();
            $table->foreign('assigned_to')->references('user_id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->unique(['result_type', 'result_id']);
            $table->index(['mission_id', 'status', 'severity']);
            $table->index(['assigned_to', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE validation_sessions
                ADD CONSTRAINT validation_sessions_status_check
                    CHECK (status IN ('open', 'completed'));

                ALTER TABLE accuracy_metrics DROP CONSTRAINT accuracy_metrics_type_check;
                ALTER TABLE accuracy_metrics
                ADD CONSTRAINT accuracy_metrics_type_check
                    CHECK (metric_type IN ('species_accuracy', 'count_precision', 'count_recall', 'count_f1', 'height_rmse', 'age_mae'));

                ALTER TABLE confidence_flags
                ADD CONSTRAINT confidence_flags_result_type_check
                    CHECK (result_type IN ('detection', 'species', 'height', 'age')),
                ADD CONSTRAINT confidence_flags_status_check
                    CHECK (status IN ('open', 'in_review', 'resolved', 'dismissed')),
                ADD CONSTRAINT confidence_flags_severity_check
                    CHECK (severity IN ('low', 'medium', 'high', 'critical'));
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE accuracy_metrics DROP CONSTRAINT accuracy_metrics_type_check;
                ALTER TABLE accuracy_metrics
                ADD CONSTRAINT accuracy_metrics_type_check
                    CHECK (metric_type IN ('species_accuracy', 'count_precision', 'height_rmse', 'age_mae'));
                SQL);
        }

        Schema::dropIfExists('confidence_flags');
        Schema::table('accuracy_metrics', function (Blueprint $table) {
            $table->dropUnique(['validation_session_id', 'metric_type']);
            $table->dropForeign(['validation_session_id']);
            $table->dropColumn('validation_session_id');
        });
        Schema::table('validation_sessions', function (Blueprint $table) {
            $table->dropIndex(['status', 'validation_date']);
            $table->dropForeign(['completed_by']);
            $table->dropColumn(['status', 'completed_at', 'completed_by']);
        });
    }
};
