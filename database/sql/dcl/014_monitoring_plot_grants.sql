\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT ON TABLE app.monitoring_plots TO mangroscan_api_rw, mangroscan_report_ro;

COMMIT;
