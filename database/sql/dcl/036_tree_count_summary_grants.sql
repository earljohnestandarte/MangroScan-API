\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT ON TABLE app.tree_count_summaries TO mangroscan_api_rw, mangroscan_report_ro;
GRANT EXECUTE ON FUNCTION app.mission_tree_counts(uuid, uuid) TO mangroscan_api_rw, mangroscan_report_ro;

GRANT SELECT, INSERT, UPDATE ON TABLE app.tree_count_summaries TO mangroscan_worker;
GRANT EXECUTE ON FUNCTION app.mission_tree_counts(uuid, uuid) TO mangroscan_worker;

COMMIT;
