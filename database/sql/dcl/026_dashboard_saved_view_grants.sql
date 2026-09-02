\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE app.dashboard_saved_views TO mangroscan_api_rw;

COMMIT;
