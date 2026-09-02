\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT, UPDATE ON TABLE app.system_settings TO mangroscan_api_rw;

COMMIT;
