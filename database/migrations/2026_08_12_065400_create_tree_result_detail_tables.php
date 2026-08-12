<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_datasets', function (Blueprint $table) {
            $table->uuid('sensor_dataset_id')->primary();
            $table->uuid('flight_session_id');
            $table->uuid('sensor_id');
            $table->string('dataset_type', 50);
            $table->string('file_name', 255);
            $table->string('storage_key', 1024)->unique();
            $table->string('file_format', 50);
            $table->timestampTz('recorded_start_at')->nullable();
            $table->timestampTz('recorded_end_at')->nullable();
            $table->string('spatial_reference', 80)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('quality_status', 30)->default('pending');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('flight_session_id')->references('flight_session_id')->on('flight_sessions')->restrictOnDelete();
            $table->foreign('sensor_id')->references('sensor_id')->on('drone_sensors')->restrictOnDelete();
            $table->index(['flight_session_id', 'dataset_type']);
            $table->index(['flight_session_id', 'quality_status']);
        });

        Schema::create('species_growth_models', function (Blueprint $table) {
            $table->uuid('growth_model_id')->primary();
            $table->uuid('species_id');
            $table->string('model_name', 150);
            $table->string('formula_type', 80);
            $table->text('formula_expression');
            $table->decimal('min_height_meters', 8, 2)->nullable();
            $table->decimal('max_height_meters', 8, 2)->nullable();
            $table->text('source_reference');
            $table->text('confidence_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign('species_id')->references('species_id')->on('mangrove_species')->restrictOnDelete();
            $table->unique(['species_id', 'model_name']);
            $table->index(['species_id', 'is_active']);
        });

        Schema::create('species_classification_results', function (Blueprint $table) {
            $table->uuid('classification_result_id')->primary();
            $table->uuid('tree_observation_id');
            $table->uuid('model_run_id');
            $table->uuid('predicted_species_id');
            $table->decimal('confidence_score', 6, 4);
            $table->unsignedInteger('rank_no');
            $table->jsonb('classification_basis')->nullable();
            $table->boolean('is_final')->default(false);
            $table->timestampTz('created_at');

            $table->foreign('tree_observation_id')->references('tree_observation_id')->on('tree_observations')->cascadeOnDelete();
            $table->foreign('model_run_id')->references('model_run_id')->on('model_runs')->restrictOnDelete();
            $table->foreign('predicted_species_id')->references('species_id')->on('mangrove_species')->restrictOnDelete();
            $table->unique(['tree_observation_id', 'model_run_id', 'rank_no']);
            $table->index(['tree_observation_id', 'is_final', 'rank_no']);
        });

        Schema::create('canopy_height_estimations', function (Blueprint $table) {
            $table->uuid('height_estimation_id')->primary();
            $table->uuid('tree_observation_id');
            $table->uuid('model_run_id')->nullable();
            $table->string('method', 80);
            $table->decimal('height_meters', 8, 2);
            $table->decimal('height_confidence_score', 6, 4)->nullable();
            $table->uuid('source_dataset_id')->nullable();
            $table->text('measurement_notes')->nullable();
            $table->boolean('is_final')->default(false);
            $table->timestampTz('created_at');

            $table->foreign('tree_observation_id')->references('tree_observation_id')->on('tree_observations')->cascadeOnDelete();
            $table->foreign('model_run_id')->references('model_run_id')->on('model_runs')->restrictOnDelete();
            $table->foreign('source_dataset_id')->references('sensor_dataset_id')->on('sensor_datasets')->restrictOnDelete();
            $table->index(['tree_observation_id', 'is_final', 'created_at']);
        });

        Schema::create('age_estimations', function (Blueprint $table) {
            $table->uuid('age_estimation_id')->primary();
            $table->uuid('tree_observation_id');
            $table->uuid('growth_model_id');
            $table->uuid('height_estimation_id');
            $table->decimal('estimated_age_years', 8, 2);
            $table->decimal('min_estimated_age_years', 8, 2)->nullable();
            $table->decimal('max_estimated_age_years', 8, 2)->nullable();
            $table->decimal('confidence_score', 6, 4)->nullable();
            $table->text('assumptions')->nullable();
            $table->boolean('is_final')->default(false);
            $table->timestampTz('created_at');

            $table->foreign('tree_observation_id')->references('tree_observation_id')->on('tree_observations')->cascadeOnDelete();
            $table->foreign('growth_model_id')->references('growth_model_id')->on('species_growth_models')->restrictOnDelete();
            $table->foreign('height_estimation_id')->references('height_estimation_id')->on('canopy_height_estimations')->restrictOnDelete();
            $table->index(['tree_observation_id', 'is_final', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE sensor_datasets
                ADD CONSTRAINT sensor_datasets_type_check CHECK (dataset_type IN ('lidar_point_cloud', 'depth_map', 'gps_log', 'imu_log')),
                ADD CONSTRAINT sensor_datasets_quality_check CHECK (quality_status IN ('pending', 'acceptable', 'rejected')),
                ADD CONSTRAINT sensor_datasets_time_check CHECK (recorded_end_at IS NULL OR recorded_start_at IS NULL OR recorded_end_at >= recorded_start_at);

                ALTER TABLE species_growth_models
                ADD CONSTRAINT species_growth_models_formula_check CHECK (formula_type IN ('linear', 'polynomial', 'lookup_table', 'custom')),
                ADD CONSTRAINT species_growth_models_height_check CHECK (min_height_meters IS NULL OR max_height_meters IS NULL OR max_height_meters >= min_height_meters);

                ALTER TABLE species_classification_results
                ADD CONSTRAINT species_classification_confidence_check CHECK (confidence_score BETWEEN 0 AND 1),
                ADD CONSTRAINT species_classification_rank_check CHECK (rank_no >= 1);

                ALTER TABLE canopy_height_estimations
                ADD CONSTRAINT canopy_height_method_check CHECK (method IN ('lidar', 'stereo_depth', 'photogrammetry', 'manual')),
                ADD CONSTRAINT canopy_height_value_check CHECK (height_meters >= 0),
                ADD CONSTRAINT canopy_height_confidence_check CHECK (height_confidence_score IS NULL OR height_confidence_score BETWEEN 0 AND 1);

                ALTER TABLE age_estimations
                ADD CONSTRAINT age_estimation_value_check CHECK (estimated_age_years >= 0 AND (min_estimated_age_years IS NULL OR min_estimated_age_years >= 0) AND (max_estimated_age_years IS NULL OR max_estimated_age_years >= estimated_age_years)),
                ADD CONSTRAINT age_estimation_confidence_check CHECK (confidence_score IS NULL OR confidence_score BETWEEN 0 AND 1)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('age_estimations');
        Schema::dropIfExists('canopy_height_estimations');
        Schema::dropIfExists('species_classification_results');
        Schema::dropIfExists('species_growth_models');
        Schema::dropIfExists('sensor_datasets');
    }
};
