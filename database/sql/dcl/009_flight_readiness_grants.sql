\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT ON TABLE app.flight_waypoints, app.flight_checklists
TO mangroscan_api_rw, mangroscan_report_ro;

COMMIT;
