\set ON_ERROR_STOP on

BEGIN;

GRANT UPDATE (
    report_title,
    report_type,
    report_status,
    summary,
    audience,
    interpretation,
    limitations,
    recommendations,
    formats,
    updated_at
) ON TABLE app.reports TO mangroscan_api_rw;

COMMIT;
