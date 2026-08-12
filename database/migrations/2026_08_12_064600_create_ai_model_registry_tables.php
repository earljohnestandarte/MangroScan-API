<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_datasets', function (Blueprint $table) {
            $table->uuid('training_dataset_id')->primary();
            $table->string('dataset_name', 150);
            $table->string('dataset_type', 80);
            $table->string('source', 150)->nullable();
            $table->text('description')->nullable();
            $table->string('version_label', 80)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('created_by')->references('user_id')->on('users')->restrictOnDelete();
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->uuid('model_id')->primary();
            $table->string('model_name', 150);
            $table->string('model_type', 80);
            $table->string('framework', 80)->nullable();
            $table->text('description')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('created_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->index(['model_type', 'model_name']);
        });

        Schema::create('ai_model_versions', function (Blueprint $table) {
            $table->uuid('model_version_id')->primary();
            $table->uuid('model_id');
            $table->string('version_label', 80);
            $table->text('model_file_path');
            $table->uuid('training_dataset_id')->nullable();
            $table->decimal('accuracy', 6, 4)->nullable();
            $table->decimal('precision_score', 6, 4)->nullable();
            $table->decimal('recall_score', 6, 4)->nullable();
            $table->decimal('f1_score', 6, 4)->nullable();
            $table->decimal('rmse', 10, 4)->nullable();
            $table->boolean('is_deployed')->default(false);
            $table->text('release_notes')->nullable();
            $table->timestampsTz();

            $table->foreign('model_id')->references('model_id')->on('ai_models')->cascadeOnDelete();
            $table->foreign('training_dataset_id')->references('training_dataset_id')->on('training_datasets')->restrictOnDelete();
            $table->unique(['model_id', 'version_label']);
            $table->index(['model_id', 'is_deployed']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE ai_models
                ADD CONSTRAINT ai_models_type_check
                CHECK (model_type IN ('species_classifier', 'tree_detector', 'height_estimator', 'age_estimator'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_versions');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('training_datasets');
    }
};
