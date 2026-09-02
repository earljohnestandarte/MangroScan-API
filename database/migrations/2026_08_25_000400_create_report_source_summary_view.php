<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE VIEW v_report_source_summary AS
            WITH tree_metrics AS (
                SELECT
                    tree.mission_id,
                    COUNT(*) AS tree_count,
                    COUNT(DISTINCT tree.final_species_id) AS species_count,
                    SUM(CASE WHEN tree.validation_status IN ('validated', 'corrected') THEN 1 ELSE 0 END) AS validated_tree_count,
                    SUM(CASE WHEN tree.validation_status = 'unvalidated' THEN 1 ELSE 0 END) AS unvalidated_tree_count,
                    SUM(CASE WHEN tree.validation_status = 'rejected' THEN 1 ELSE 0 END) AS rejected_tree_count
                FROM tree_observations AS tree
                WHERE tree.deleted_at IS NULL
                GROUP BY tree.mission_id
            ),
            ground_truth_metrics AS (
                SELECT validation_session_id, COUNT(*) AS ground_truth_count
                FROM ground_truth_tree_records
                GROUP BY validation_session_id
            ),
            validation_metrics AS (
                SELECT
                    session.mission_id,
                    COUNT(*) AS validation_session_count,
                    SUM(CASE WHEN session.status = 'open' THEN 1 ELSE 0 END) AS open_validation_session_count,
                    SUM(CASE WHEN session.status = 'completed' THEN 1 ELSE 0 END) AS completed_validation_session_count,
                    SUM(COALESCE(truth.ground_truth_count, 0)) AS ground_truth_count
                FROM validation_sessions AS session
                LEFT JOIN ground_truth_metrics AS truth
                    ON truth.validation_session_id = session.validation_session_id
                GROUP BY session.mission_id
            ),
            accuracy_metrics_by_mission AS (
                SELECT
                    metric.mission_id,
                    MAX(CASE WHEN metric.metric_type = 'species_accuracy' THEN metric.metric_value END) AS species_accuracy,
                    MAX(CASE WHEN metric.metric_type = 'count_precision' THEN metric.metric_value END) AS count_precision,
                    MAX(CASE WHEN metric.metric_type = 'count_recall' THEN metric.metric_value END) AS count_recall,
                    MAX(CASE WHEN metric.metric_type = 'count_f1' THEN metric.metric_value END) AS count_f1,
                    MAX(CASE WHEN metric.metric_type = 'height_rmse' THEN metric.metric_value END) AS height_rmse,
                    MAX(CASE WHEN metric.metric_type = 'age_mae' THEN metric.metric_value END) AS age_mae
                FROM v_mission_accuracy_summary AS metric
                GROUP BY metric.mission_id
            )
            SELECT
                site.organization_id,
                mission.mission_id,
                mission.mission_code,
                mission.mission_title,
                mission.mission_status,
                site.site_id,
                site.site_code,
                site.site_name,
                COALESCE(tree.tree_count, 0) AS tree_count,
                COALESCE(tree.species_count, 0) AS species_count,
                COALESCE(tree.validated_tree_count, 0) AS validated_tree_count,
                COALESCE(tree.unvalidated_tree_count, 0) AS unvalidated_tree_count,
                COALESCE(tree.rejected_tree_count, 0) AS rejected_tree_count,
                COALESCE(validation.validation_session_count, 0) AS validation_session_count,
                COALESCE(validation.open_validation_session_count, 0) AS open_validation_session_count,
                COALESCE(validation.completed_validation_session_count, 0) AS completed_validation_session_count,
                COALESCE(validation.ground_truth_count, 0) AS ground_truth_count,
                accuracy.species_accuracy,
                accuracy.count_precision,
                accuracy.count_recall,
                accuracy.count_f1,
                accuracy.height_rmse,
                accuracy.age_mae
            FROM survey_missions AS mission
            INNER JOIN survey_sites AS site ON site.site_id = mission.site_id
            LEFT JOIN tree_metrics AS tree ON tree.mission_id = mission.mission_id
            LEFT JOIN validation_metrics AS validation ON validation.mission_id = mission.mission_id
            LEFT JOIN accuracy_metrics_by_mission AS accuracy ON accuracy.mission_id = mission.mission_id
            WHERE mission.deleted_at IS NULL
                AND site.deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS v_report_source_summary');
    }
};
