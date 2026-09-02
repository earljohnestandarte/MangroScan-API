\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT, INSERT ON TABLE app.site_access_permissions TO mangroscan_api_rw;

COMMIT;
