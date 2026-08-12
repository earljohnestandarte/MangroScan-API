\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT, INSERT, UPDATE ON TABLE app.sync_requests TO mangroscan_api_rw;
GRANT SELECT, INSERT ON TABLE app.sync_change_log, app.sync_conflicts TO mangroscan_api_rw;

COMMIT;
