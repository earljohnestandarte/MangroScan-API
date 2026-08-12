\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE app.password_reset_tokens TO mangroscan_api_rw;

COMMIT;
