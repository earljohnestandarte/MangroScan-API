\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT, INSERT, UPDATE ON TABLE app.flight_sessions TO mangroscan_api_rw;
GRANT SELECT ON TABLE app.flight_sessions TO mangroscan_report_ro;

COMMIT;
