<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processing_jobs', function (Blueprint $table): void {
            $table->string('request_id', 120)->nullable()->index();
            $table->unsignedInteger('processing_time_ms')->nullable();
        });

        Schema::create('ai_inference_results', function (Blueprint $table): void {
            $table->uuid('ai_inference_result_id')->primary();
            $table->uuid('processing_job_id');
            $table->uuid('model_run_id')->nullable();
            $table->uuid('mission_id');
            $table->uuid('flight_session_id');
            $table->uuid('source_media_id');
            $table->uuid('tree_observation_id')->nullable();
            $table->uuid('classification_result_id')->nullable();
            $table->integer('frame_number')->nullable();
            $table->integer('detection_index')->nullable();
            $table->jsonb('bounding_box')->nullable();
            $table->decimal('detection_confidence', 6, 4)->nullable();
            $table->string('predicted_species_name', 150)->nullable();
            $table->decimal('species_confidence', 6, 4)->nullable();
            $table->jsonb('result_metadata')->nullable();
            $table->timestampsTz();
            $table->foreign('processing_job_id')->references('processing_job_id')->on('processing_jobs')->cascadeOnDelete();
            $table->foreign('model_run_id')->references('model_run_id')->on('model_runs')->nullOnDelete();
            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->cascadeOnDelete();
            $table->foreign('flight_session_id')->references('flight_session_id')->on('flight_sessions')->cascadeOnDelete();
            $table->foreign('source_media_id')->references('media_asset_id')->on('media_assets')->cascadeOnDelete();
            $table->index(['processing_job_id', 'created_at']);
            $table->index(['mission_id', 'flight_session_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_inference_results ADD CONSTRAINT ai_inference_detection_confidence_check CHECK (detection_confidence IS NULL OR detection_confidence BETWEEN 0 AND 1)');
            DB::statement('ALTER TABLE ai_inference_results ADD CONSTRAINT ai_inference_species_confidence_check CHECK (species_confidence IS NULL OR species_confidence BETWEEN 0 AND 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_inference_results');
        Schema::table('processing_jobs', function (Blueprint $table): void {
            $table->dropColumn(['request_id', 'processing_time_ms']);
        });
    }
};
