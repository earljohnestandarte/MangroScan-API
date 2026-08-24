\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT (
    export_file_id, report_id, mission_id, export_type, file_name,
    file_size_bytes, exported_by, exported_at
) ON TABLE app.exported_files TO mangroscan_api_rw;

COMMIT;
