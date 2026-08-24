\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT (file_path) ON TABLE app.exported_files TO mangroscan_api_rw;

COMMIT;
