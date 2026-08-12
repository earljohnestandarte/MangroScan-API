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
        Schema::create('photogrammetry_products', function (Blueprint $table) use ($driver) {
            $table->uuid('product_id')->primary();
            $table->uuid('mission_id');
            $table->uuid('processing_job_id');
            $table->string('product_type', 50);
            $table->string('file_name', 255);
            $table->string('storage_key', 1024)->unique();
            $table->string('file_format', 50);
            $table->decimal('resolution_cm_per_pixel', 8, 2)->nullable();
            $table->string('spatial_reference', 80);
            if ($driver === 'pgsql') {
                $table->geometry('bounding_geom', 'polygon', 4326)->nullable();
            } else {
                $table->json('bounding_geom')->nullable();
            }
            $table->timestampsTz();
            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->cascadeOnDelete();
            $table->foreign('processing_job_id')->references('processing_job_id')->on('processing_jobs')->restrictOnDelete();
            $table->index(['mission_id', 'product_type']);
            if ($driver === 'pgsql') {
                $table->spatialIndex('bounding_geom');
            }
        });

        Schema::create('geospatial_layers', function (Blueprint $table) {
            $table->uuid('layer_id')->primary();
            $table->uuid('mission_id');
            $table->string('layer_name', 150);
            $table->string('layer_type', 50);
            $table->string('storage_key', 1024)->unique();
            $table->jsonb('style_config')->nullable();
            $table->boolean('is_visible_default')->default(true);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->cascadeOnDelete();
            $table->foreign('created_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->index(['mission_id', 'layer_type']);
            $table->index(['mission_id', 'is_visible_default']);
        });

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE photogrammetry_products
                ADD CONSTRAINT photogrammetry_products_type_check CHECK (product_type IN ('orthomosaic', 'point_cloud', 'dsm', 'dtm', 'chm')),
                ADD CONSTRAINT photogrammetry_products_resolution_check CHECK (resolution_cm_per_pixel IS NULL OR resolution_cm_per_pixel > 0);

                ALTER TABLE geospatial_layers
                ADD CONSTRAINT geospatial_layers_type_check CHECK (layer_type IN ('tree_points', 'species_map', 'canopy_height', 'orthomosaic'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('geospatial_layers');
        Schema::dropIfExists('photogrammetry_products');
    }
};
