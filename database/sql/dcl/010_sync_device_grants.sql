\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT, INSERT, UPDATE ON TABLE app.sync_devices TO mangroscan_api_rw;

COMMIT;
