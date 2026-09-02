\set ON_ERROR_STOP on

BEGIN;

REVOKE ALL ON TABLE app.v_report_source_summary
FROM PUBLIC, mangroscan_api_rw, mangroscan_worker, mangroscan_report_ro, mangroscan_auditor;

GRANT SELECT ON TABLE app.v_report_source_summary
TO mangroscan_api_rw, mangroscan_report_ro;

COMMIT;
