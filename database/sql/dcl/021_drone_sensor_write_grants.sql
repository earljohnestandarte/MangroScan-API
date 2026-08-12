\set ON_ERROR_STOP on

BEGIN;

GRANT INSERT ON TABLE app.drone_sensors TO mangroscan_api_rw;

COMMIT;
