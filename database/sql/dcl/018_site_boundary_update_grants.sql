\set ON_ERROR_STOP on

BEGIN;

GRANT UPDATE ON TABLE app.site_boundaries TO mangroscan_api_rw;

COMMIT;
