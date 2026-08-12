\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT ON TABLE app.drones TO mangroscan_api_rw, mangroscan_report_ro;

COMMIT;
