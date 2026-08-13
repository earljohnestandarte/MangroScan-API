<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        Schema::create('validation_sessions', function (Blueprint $table) {
            $table->uuid('validation_session_id')->primary();
            $table->uuid('mission_id');
            $table->uuid('site_id');
            $table->uuid('plot_id')->nullable();
            $table->uuid('validated_by');
            $table->date('validation_date');
            $table->string('method', 80);
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->restrictOnDelete();
            $table->foreign('site_id')->references('site_id')->on('survey_sites')->restrictOnDelete();
            $table->foreign('plot_id')->references('plot_id')->on('monitoring_plots')->restrictOnDelete();
            $table->foreign('validated_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->index(['mission_id', 'validation_date']);
            $table->index(['site_id', 'validation_date']);
            $table->index('plot_id');
            $table->index('validated_by');
        });

        Schema::create('ground_truth_tree_records', function (Blueprint $table) use ($driver) {
            $table->uuid('ground_truth_id')->primary();
            $table->uuid('validation_session_id');
            $table->uuid('species_id')->nullable();
            if ($driver === 'pgsql') {
                $table->geometry('ground_location', 'point', 4326);
            } else {
                $table->json('ground_location');
            }
            $table->decimal('measured_height_meters', 8, 2)->nullable();
            $table->decimal('estimated_age_years', 8, 2)->nullable();
            $table->decimal('diameter_cm', 8, 2)->nullable();
            $table->string('health_status', 50);
            $table->text('photo_path')->nullable();
            $table->text('remarks')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('validation_session_id')
                ->references('validation_session_id')->on('validation_sessions')->cascadeOnDelete();
            $table->foreign('species_id')->references('species_id')->on('mangrove_species')->restrictOnDelete();
            $table->index(['validation_session_id', 'created_at']);
            $table->index('species_id');
            if ($driver === 'pgsql') {
                $table->spatialIndex('ground_location');
            }
        });

        Schema::create('validation_matches', function (Blueprint $table) {
            $table->uuid('validation_match_id')->primary();
            $table->uuid('ground_truth_id');
            $table->uuid('tree_observation_id')->nullable();
            $table->string('match_status', 30);
            $table->decimal('distance_error_meters', 10, 4)->nullable();
            $table->boolean('species_correct')->nullable();
            $table->decimal('height_error_meters', 10, 4)->nullable();
            $table->decimal('age_error_years', 10, 4)->nullable();
            $table->uuid('validated_by');
            $table->timestampTz('validated_at')->useCurrent();

            $table->foreign('ground_truth_id')
                ->references('ground_truth_id')->on('ground_truth_tree_records')->cascadeOnDelete();
            $table->foreign('tree_observation_id')
                ->references('tree_observation_id')->on('tree_observations')->restrictOnDelete();
            $table->foreign('validated_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->index(['ground_truth_id', 'validated_at']);
            $table->index('tree_observation_id');
            $table->index('validated_by');
        });

        Schema::create('accuracy_metrics', function (Blueprint $table) {
            $table->uuid('accuracy_metric_id')->primary();
            $table->uuid('mission_id');
            $table->uuid('model_version_id')->nullable();
            $table->string('metric_type', 80);
            $table->decimal('metric_value', 12, 6);
            $table->unsignedInteger('sample_size')->nullable();
            $table->timestampTz('computed_at')->useCurrent();
            $table->text('notes')->nullable();

            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->restrictOnDelete();
            $table->foreign('model_version_id')
                ->references('model_version_id')->on('ai_model_versions')->restrictOnDelete();
            $table->index(['mission_id', 'metric_type', 'computed_at']);
            $table->index('model_version_id');
        });

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE validation_sessions
                ADD CONSTRAINT validation_sessions_method_check
                    CHECK (method IN ('ground_survey', 'expert_review', 'sample_plot'));

                ALTER TABLE ground_truth_tree_records
                ADD CONSTRAINT ground_truth_tree_records_health_status_check
                    CHECK (health_status IN ('healthy', 'stressed', 'dead', 'unknown')),
                ADD CONSTRAINT ground_truth_tree_records_measurements_check
                    CHECK (
                        (measured_height_meters IS NULL OR measured_height_meters >= 0)
                        AND (estimated_age_years IS NULL OR estimated_age_years >= 0)
                        AND (diameter_cm IS NULL OR diameter_cm >= 0)
                    );

                ALTER TABLE validation_matches
                ADD CONSTRAINT validation_matches_status_check
                    CHECK (match_status IN ('matched', 'false_positive', 'false_negative', 'corrected')),
                ADD CONSTRAINT validation_matches_error_values_check
                    CHECK (
                        (distance_error_meters IS NULL OR distance_error_meters >= 0)
                        AND (height_error_meters IS NULL OR height_error_meters >= 0)
                        AND (age_error_years IS NULL OR age_error_years >= 0)
                    );

                ALTER TABLE accuracy_metrics
                ADD CONSTRAINT accuracy_metrics_type_check
                    CHECK (metric_type IN ('species_accuracy', 'count_precision', 'height_rmse', 'age_mae')),
                ADD CONSTRAINT accuracy_metrics_value_check
                    CHECK (metric_value >= 0 AND (sample_size IS NULL OR sample_size >= 0));
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accuracy_metrics');
        Schema::dropIfExists('validation_matches');
        Schema::dropIfExists('ground_truth_tree_records');
        Schema::dropIfExists('validation_sessions');
    }
};
