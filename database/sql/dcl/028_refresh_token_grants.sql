\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE app.refresh_tokens TO mangroscan_api_rw;

COMMIT;
