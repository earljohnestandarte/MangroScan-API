\set ON_ERROR_STOP on

BEGIN;

GRANT INSERT ON TABLE app.drones TO mangroscan_api_rw;

COMMIT;
