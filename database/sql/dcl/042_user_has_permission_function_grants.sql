\set ON_ERROR_STOP on

BEGIN;
GRANT EXECUTE ON FUNCTION app.fn_user_has_permission(uuid, text) TO mangroscan_api_rw;
COMMIT;
