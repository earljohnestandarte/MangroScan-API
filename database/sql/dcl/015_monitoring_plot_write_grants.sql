\set ON_ERROR_STOP on

BEGIN;

GRANT INSERT ON TABLE app.monitoring_plots TO mangroscan_api_rw;

COMMIT;
