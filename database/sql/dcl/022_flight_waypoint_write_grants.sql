\set ON_ERROR_STOP on

BEGIN;

GRANT INSERT, DELETE ON TABLE app.flight_waypoints TO mangroscan_api_rw;

COMMIT;
