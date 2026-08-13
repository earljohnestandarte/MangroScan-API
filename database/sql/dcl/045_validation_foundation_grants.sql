\set ON_ERROR_STOP on

BEGIN;

REVOKE ALL PRIVILEGES ON TABLE
    app.validation_sessions,
    app.ground_truth_tree_records,
    app.validation_matches,
    app.accuracy_metrics
FROM PUBLIC, mangroscan_api_rw, mangroscan_worker, mangroscan_report_ro, mangroscan_auditor;

COMMIT;
