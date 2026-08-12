\set ON_ERROR_STOP on

BEGIN;
GRANT SELECT ON TABLE app.v_user_effective_permissions TO mangroscan_api_rw;
COMMIT;
