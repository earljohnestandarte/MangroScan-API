\set ON_ERROR_STOP on

BEGIN;

REVOKE ALL ON TABLE app.export_jobs, app.exported_files
FROM PUBLIC, mangroscan_api_rw, mangroscan_worker, mangroscan_report_ro, mangroscan_auditor;

GRANT SELECT ON TABLE app.export_jobs TO mangroscan_api_rw;
GRANT INSERT (
    export_job_id, organization_id, report_id, mission_id, export_type, filters, options,
    job_status, created_by, idempotency_key, request_fingerprint, created_at, updated_at
) ON TABLE app.export_jobs TO mangroscan_api_rw;

GRANT SELECT ON TABLE app.export_jobs TO mangroscan_worker;
GRANT UPDATE (
    job_status, exported_file_id, started_at, completed_at, error_message, updated_at
) ON TABLE app.export_jobs TO mangroscan_worker;
GRANT SELECT ON TABLE app.tree_observations, app.mangrove_species TO mangroscan_worker;
GRANT INSERT (
    export_file_id, report_id, mission_id, export_type, file_name, file_path,
    file_size_bytes, exported_by, exported_at
) ON TABLE app.exported_files TO mangroscan_worker;
GRANT INSERT (
    audit_log_id, user_id, action, table_name, record_id, old_values, new_values,
    ip_address, user_agent, request_id, created_at
) ON TABLE app.audit_logs TO mangroscan_worker;

COMMIT;
