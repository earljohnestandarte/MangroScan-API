\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT, INSERT ON TABLE app.media_upload_sessions TO mangroscan_api_rw;

COMMIT;
