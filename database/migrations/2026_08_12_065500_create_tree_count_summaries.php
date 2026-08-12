<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tree_count_summaries', function (Blueprint $table) {
            $table->uuid('tree_count_summary_id')->primary();
            $table->uuid('mission_id');
            $table->uuid('site_id');
            $table->uuid('species_id')->nullable();
            $table->uuid('model_run_id')->nullable();
            $table->unsignedInteger('total_detected_trees');
            $table->unsignedInteger('validated_tree_count')->nullable();
            $table->decimal('estimated_density_per_hectare', 12, 4)->nullable();
            $table->decimal('count_confidence_score', 6, 4)->nullable();
            $table->timestampsTz();

            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->cascadeOnDelete();
            $table->foreign('site_id')->references('site_id')->on('survey_sites')->restrictOnDelete();
            $table->foreign('species_id')->references('species_id')->on('mangrove_species')->restrictOnDelete();
            $table->foreign('model_run_id')->references('model_run_id')->on('model_runs')->restrictOnDelete();
            $table->index(['mission_id', 'species_id', 'created_at']);
            $table->index(['site_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE tree_count_summaries
                ADD CONSTRAINT tree_count_summaries_total_check CHECK (total_detected_trees >= 0),
                ADD CONSTRAINT tree_count_summaries_validated_check CHECK (validated_tree_count IS NULL OR validated_tree_count <= total_detected_trees),
                ADD CONSTRAINT tree_count_summaries_density_check CHECK (estimated_density_per_hectare IS NULL OR estimated_density_per_hectare >= 0),
                ADD CONSTRAINT tree_count_summaries_confidence_check CHECK (count_confidence_score IS NULL OR count_confidence_score BETWEEN 0 AND 1);

                CREATE OR REPLACE FUNCTION app.mission_tree_counts(p_mission_id uuid, p_species_id uuid DEFAULT NULL)
                RETURNS TABLE(species_id uuid, total_detected_trees bigint, validated_tree_count bigint)
                LANGUAGE sql
                STABLE
                SECURITY INVOKER
                SET search_path = app, pg_temp
                AS $function$
                    SELECT tree.final_species_id,
                        COUNT(*) AS total_detected_trees,
                        COUNT(*) FILTER (WHERE tree.validation_status IN ('validated', 'corrected')) AS validated_tree_count
                    FROM app.tree_observations AS tree
                    WHERE tree.mission_id = p_mission_id
                        AND tree.deleted_at IS NULL
                        AND (p_species_id IS NULL OR tree.final_species_id = p_species_id)
                        AND (p_species_id IS NOT NULL OR tree.final_species_id IS NOT NULL)
                    GROUP BY tree.final_species_id
                    ORDER BY tree.final_species_id
                $function$
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS app.mission_tree_counts(uuid, uuid)');
        }

        Schema::dropIfExists('tree_count_summaries');
    }
};
