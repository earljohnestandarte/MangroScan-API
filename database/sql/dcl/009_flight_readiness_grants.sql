\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT ON TABLE app.flight_waypoints TO mangroscan_api_rw, mangroscan_report_ro;
GRANT SELECT, INSERT ON TABLE app.flight_checklists TO mangroscan_api_rw;
GRANT SELECT ON TABLE app.flight_checklists TO mangroscan_report_ro;

COMMIT;
