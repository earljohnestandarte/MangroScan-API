\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT ON TABLE app.notification_logs TO mangroscan_api_rw;

COMMIT;
