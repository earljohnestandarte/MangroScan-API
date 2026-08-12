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

        Schema::create('mangrove_species', function (Blueprint $table) {
            $table->uuid('species_id')->primary();
            $table->string('scientific_name', 150)->unique();
            $table->string('common_name', 150)->nullable();
            $table->string('local_name', 150)->nullable();
            $table->text('description')->nullable();
            $table->decimal('typical_growth_rate_cm_per_year', 8, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['is_active', 'scientific_name']);
        });

        Schema::create('mangrove_tree_entities', function (Blueprint $table) use ($driver) {
            $table->uuid('tree_entity_id')->primary();
            $table->uuid('site_id');
            $table->string('persistent_tree_code', 80)->unique();
            $table->uuid('first_detected_mission_id')->nullable();
            if ($driver === 'pgsql') {
                $table->geometry('initial_location', 'point', 4326);
            } else {
                $table->json('initial_location');
            }
            $table->string('current_status', 30)->default('alive');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('site_id')->references('site_id')->on('survey_sites')->restrictOnDelete();
            $table->foreign('first_detected_mission_id')->references('mission_id')->on('survey_missions')->restrictOnDelete();
            $table->index(['site_id', 'current_status']);
            if ($driver === 'pgsql') {
                $table->spatialIndex('initial_location');
            }
        });

        Schema::create('tree_observations', function (Blueprint $table) use ($driver) {
            $table->uuid('tree_observation_id')->primary();
            $table->uuid('tree_entity_id')->nullable();
            $table->uuid('mission_id');
            $table->uuid('flight_session_id');
            $table->uuid('model_run_id')->nullable();
            $table->uuid('source_media_id')->nullable();
            $table->string('tree_code', 80);
            if ($driver === 'pgsql') {
                $table->geometry('tree_location', 'point', 4326);
                $table->geometry('crown_polygon', 'polygon', 4326)->nullable();
            } else {
                $table->json('tree_location');
                $table->json('crown_polygon')->nullable();
            }
            $table->jsonb('bounding_box')->nullable();
            $table->decimal('detection_confidence', 6, 4)->nullable();
            $table->uuid('final_species_id')->nullable();
            $table->decimal('final_height_meters', 8, 2)->nullable();
            $table->decimal('final_estimated_age_years', 8, 2)->nullable();
            $table->string('validation_status', 30)->default('unvalidated');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tree_entity_id')->references('tree_entity_id')->on('mangrove_tree_entities')->restrictOnDelete();
            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->restrictOnDelete();
            $table->foreign('flight_session_id')->references('flight_session_id')->on('flight_sessions')->restrictOnDelete();
            $table->foreign('model_run_id')->references('model_run_id')->on('model_runs')->restrictOnDelete();
            $table->foreign('source_media_id')->references('media_asset_id')->on('media_assets')->restrictOnDelete();
            $table->foreign('final_species_id')->references('species_id')->on('mangrove_species')->restrictOnDelete();
            $table->unique(['mission_id', 'tree_code']);
            $table->index(['mission_id', 'validation_status']);
            $table->index(['flight_session_id', 'validation_status']);
            $table->index(['final_species_id', 'detection_confidence']);
            $table->index(['created_at', 'tree_observation_id']);
            if ($driver === 'pgsql') {
                $table->spatialIndex('tree_location');
                $table->spatialIndex('crown_polygon');
            }
        });

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE mangrove_species
                ADD CONSTRAINT mangrove_species_growth_rate_check
                    CHECK (typical_growth_rate_cm_per_year IS NULL OR typical_growth_rate_cm_per_year >= 0);

                ALTER TABLE mangrove_tree_entities
                ADD CONSTRAINT mangrove_tree_entities_status_check
                    CHECK (current_status IN ('alive', 'missing', 'dead', 'uncertain'));

                ALTER TABLE tree_observations
                ADD CONSTRAINT tree_observations_confidence_check
                    CHECK (detection_confidence IS NULL OR detection_confidence BETWEEN 0 AND 1),
                ADD CONSTRAINT tree_observations_height_check
                    CHECK (final_height_meters IS NULL OR final_height_meters >= 0),
                ADD CONSTRAINT tree_observations_age_check
                    CHECK (final_estimated_age_years IS NULL OR final_estimated_age_years >= 0),
                ADD CONSTRAINT tree_observations_validation_status_check
                    CHECK (validation_status IN ('unvalidated', 'validated', 'corrected', 'rejected'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tree_observations');
        Schema::dropIfExists('mangrove_tree_entities');
        Schema::dropIfExists('mangrove_species');
    }
};
