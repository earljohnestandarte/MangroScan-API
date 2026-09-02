<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_datasets', function (Blueprint $table) {
            $table->index(['dataset_type', 'source']);
            $table->index(['created_at', 'training_dataset_id']);
        });

        Schema::create('training_dataset_items', function (Blueprint $table) {
            $table->uuid('dataset_item_id')->primary();
            $table->uuid('training_dataset_id');
            $table->uuid('media_asset_id')->nullable();
            $table->text('label_file_path');
            $table->string('label_format', 30);
            $table->uuid('species_id')->nullable();
            $table->string('annotation_status', 30);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('training_dataset_id')->references('training_dataset_id')->on('training_datasets')->cascadeOnDelete();
            $table->foreign('media_asset_id')->references('media_asset_id')->on('media_assets')->restrictOnDelete();
            $table->foreign('species_id')->references('species_id')->on('mangrove_species')->restrictOnDelete();
            $table->foreign('created_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->unique(['training_dataset_id', 'label_file_path']);
            $table->index(['training_dataset_id', 'annotation_status']);
        });

        Schema::create('annotation_projects', function (Blueprint $table) {
            $table->uuid('annotation_project_id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 150);
            $table->string('dataset_type', 80);
            $table->uuid('mission_id')->nullable();
            $table->string('status', 30);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('organization_id')->references('organization_id')->on('organizations')->restrictOnDelete();
            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->restrictOnDelete();
            $table->foreign('created_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'status', 'created_at']);
        });

        Schema::create('annotation_items', function (Blueprint $table) {
            $table->uuid('annotation_item_id')->primary();
            $table->uuid('annotation_project_id');
            $table->uuid('dataset_item_id')->nullable();
            $table->uuid('media_asset_id')->nullable();
            $table->string('status', 30)->default('planned');
            $table->timestampsTz();

            $table->foreign('annotation_project_id')->references('annotation_project_id')->on('annotation_projects')->cascadeOnDelete();
            $table->foreign('dataset_item_id')->references('dataset_item_id')->on('training_dataset_items')->restrictOnDelete();
            $table->foreign('media_asset_id')->references('media_asset_id')->on('media_assets')->restrictOnDelete();
            $table->index(['annotation_project_id', 'status']);
        });

        Schema::create('annotation_objects', function (Blueprint $table) {
            $table->uuid('annotation_object_id')->primary();
            $table->uuid('annotation_item_id');
            $table->uuid('class_id');
            $table->jsonb('bbox')->nullable();
            $table->jsonb('polygon')->nullable();
            $table->jsonb('attributes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('annotation_item_id')->references('annotation_item_id')->on('annotation_items')->cascadeOnDelete();
            $table->foreign('class_id')->references('species_id')->on('mangrove_species')->restrictOnDelete();
            $table->foreign('created_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->index(['annotation_item_id', 'class_id']);
        });

        Schema::create('annotation_exports', function (Blueprint $table) {
            $table->uuid('annotation_export_id')->primary();
            $table->uuid('annotation_project_id');
            $table->string('format', 20);
            $table->string('file_name', 255);
            $table->string('storage_key', 1024)->unique();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('annotation_project_id')->references('annotation_project_id')->on('annotation_projects')->cascadeOnDelete();
            $table->foreign('created_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->index(['annotation_project_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE training_dataset_items
                ADD CONSTRAINT training_dataset_items_format_check
                    CHECK (label_format IN ('coco', 'yolo', 'csv', 'geojson', 'json')),
                ADD CONSTRAINT training_dataset_items_status_check
                    CHECK (annotation_status IN ('planned', 'in_progress', 'completed', 'reviewed', 'rejected'));

                ALTER TABLE annotation_projects
                ADD CONSTRAINT annotation_projects_status_check
                    CHECK (status IN ('planned', 'active', 'paused', 'completed', 'archived'));

                ALTER TABLE annotation_items
                ADD CONSTRAINT annotation_items_status_check
                    CHECK (status IN ('planned', 'in_progress', 'completed', 'reviewed', 'rejected'));

                ALTER TABLE annotation_exports
                ADD CONSTRAINT annotation_exports_format_check
                    CHECK (format IN ('coco', 'yolo', 'csv', 'geojson'));
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('annotation_exports');
        Schema::dropIfExists('annotation_objects');
        Schema::dropIfExists('annotation_items');
        Schema::dropIfExists('annotation_projects');
        Schema::dropIfExists('training_dataset_items');

        Schema::table('training_datasets', function (Blueprint $table) {
            $table->dropIndex(['dataset_type', 'source']);
            $table->dropIndex(['created_at', 'training_dataset_id']);
        });
    }
};
