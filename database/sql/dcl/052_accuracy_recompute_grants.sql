\set ON_ERROR_STOP on

BEGIN;

REVOKE INSERT, UPDATE, DELETE ON TABLE app.accuracy_metrics FROM mangroscan_api_rw;

GRANT INSERT (
    accuracy_metric_id,
    validation_session_id,
    mission_id,
    model_version_id,
    metric_type,
    metric_value,
    sample_size,
    computed_at,
    notes
) ON TABLE app.accuracy_metrics TO mangroscan_api_rw;

GRANT UPDATE (
    mission_id,
    model_version_id,
    metric_value,
    sample_size,
    computed_at,
    notes
) ON TABLE app.accuracy_metrics TO mangroscan_api_rw;

COMMIT;
