<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE VIEW v_mission_accuracy_summary AS
            SELECT
                accuracy_metric_id,
                validation_session_id,
                mission_id,
                model_version_id,
                metric_type,
                metric_value,
                sample_size,
                computed_at
            FROM (
                SELECT
                    metric.*,
                    ROW_NUMBER() OVER (
                        PARTITION BY metric.mission_id, metric.metric_type
                        ORDER BY metric.computed_at DESC, metric.accuracy_metric_id DESC
                    ) AS metric_rank
                FROM accuracy_metrics AS metric
            ) AS ranked_metrics
            WHERE metric_rank = 1
            SQL);

        $create = DB::getDriverName() === 'pgsql'
            ? 'CREATE MATERIALIZED VIEW mv_dashboard_mission_metrics AS'
            : 'CREATE VIEW mv_dashboard_mission_metrics AS';

        DB::unprepared($create.<<<'SQL'

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
                SELECT
                    truth.validation_session_id,
                    COUNT(*) AS ground_truth_count
                FROM ground_truth_tree_records AS truth
                GROUP BY truth.validation_session_id
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
            processing_metrics AS (
                SELECT
                    job.mission_id,
                    COUNT(*) AS processing_job_count,
                    SUM(CASE WHEN job.job_status = 'queued' THEN 1 ELSE 0 END) AS queued_processing_job_count,
                    SUM(CASE WHEN job.job_status = 'running' THEN 1 ELSE 0 END) AS running_processing_job_count,
                    SUM(CASE WHEN job.job_status = 'completed' THEN 1 ELSE 0 END) AS completed_processing_job_count,
                    SUM(CASE WHEN job.job_status = 'failed' THEN 1 ELSE 0 END) AS failed_processing_job_count,
                    SUM(CASE WHEN job.job_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_processing_job_count
                FROM processing_jobs AS job
                GROUP BY job.mission_id
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
                mission.site_id,
                mission.mission_id,
                mission.mission_code,
                mission.mission_title,
                mission.mission_status,
                mission.planned_start_at,
                mission.planned_end_at,
                mission.actual_start_at,
                mission.actual_end_at,
                COALESCE(tree.tree_count, 0) AS tree_count,
                COALESCE(tree.species_count, 0) AS species_count,
                COALESCE(tree.validated_tree_count, 0) AS validated_tree_count,
                COALESCE(tree.unvalidated_tree_count, 0) AS unvalidated_tree_count,
                COALESCE(tree.rejected_tree_count, 0) AS rejected_tree_count,
                COALESCE(validation.validation_session_count, 0) AS validation_session_count,
                COALESCE(validation.open_validation_session_count, 0) AS open_validation_session_count,
                COALESCE(validation.completed_validation_session_count, 0) AS completed_validation_session_count,
                COALESCE(validation.ground_truth_count, 0) AS ground_truth_count,
                COALESCE(processing.processing_job_count, 0) AS processing_job_count,
                COALESCE(processing.queued_processing_job_count, 0) AS queued_processing_job_count,
                COALESCE(processing.running_processing_job_count, 0) AS running_processing_job_count,
                COALESCE(processing.completed_processing_job_count, 0) AS completed_processing_job_count,
                COALESCE(processing.failed_processing_job_count, 0) AS failed_processing_job_count,
                COALESCE(processing.cancelled_processing_job_count, 0) AS cancelled_processing_job_count,
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
            LEFT JOIN processing_metrics AS processing ON processing.mission_id = mission.mission_id
            LEFT JOIN accuracy_metrics_by_mission AS accuracy ON accuracy.mission_id = mission.mission_id
            WHERE mission.deleted_at IS NULL
                AND site.deleted_at IS NULL
            SQL);

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX mv_dashboard_mission_metrics_mission_id_unique
                    ON mv_dashboard_mission_metrics (mission_id);
                CREATE INDEX mv_dashboard_mission_metrics_organization_status_index
                    ON mv_dashboard_mission_metrics (organization_id, mission_status);
                CREATE INDEX mv_dashboard_mission_metrics_site_status_index
                    ON mv_dashboard_mission_metrics (site_id, mission_status);
                CREATE INDEX mv_dashboard_mission_metrics_organization_planned_start_index
                    ON mv_dashboard_mission_metrics (organization_id, planned_start_at);
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP MATERIALIZED VIEW IF EXISTS mv_dashboard_mission_metrics');
        } else {
            DB::unprepared('DROP VIEW IF EXISTS mv_dashboard_mission_metrics');
        }

        DB::unprepared('DROP VIEW IF EXISTS v_mission_accuracy_summary');
    }
};
