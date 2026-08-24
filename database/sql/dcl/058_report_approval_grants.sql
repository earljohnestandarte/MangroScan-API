\set ON_ERROR_STOP on

BEGIN;

GRANT UPDATE (
    report_status,
    generated_by,
    approved_by,
    updated_at
) ON TABLE app.reports TO mangroscan_api_rw;

COMMIT;
