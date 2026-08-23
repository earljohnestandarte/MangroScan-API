\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT, INSERT, UPDATE ON TABLE app.confidence_flags TO mangroscan_api_rw;
GRANT SELECT ON TABLE app.confidence_flags TO mangroscan_report_ro;

GRANT SELECT, UPDATE (status, notes, completed_at, completed_by, updated_at)
    ON TABLE app.validation_sessions TO mangroscan_api_rw;
GRANT SELECT ON TABLE app.ground_truth_tree_records, app.validation_matches TO mangroscan_api_rw;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE app.accuracy_metrics TO mangroscan_api_rw;
GRANT SELECT ON TABLE app.validation_sessions, app.ground_truth_tree_records,
    app.validation_matches, app.accuracy_metrics TO mangroscan_report_ro;

GRANT SELECT, INSERT, UPDATE ON TABLE app.photogrammetry_products TO mangroscan_worker;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE app.geospatial_layers TO mangroscan_worker;

COMMIT;
