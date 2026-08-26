\set ON_ERROR_STOP on

BEGIN;

GRANT INSERT ON TABLE app.sensor_calibrations TO mangroscan_api_rw;

COMMIT;