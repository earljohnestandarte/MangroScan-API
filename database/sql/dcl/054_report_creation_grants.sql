\set ON_ERROR_STOP on

BEGIN;

GRANT INSERT (
    report_id,
    mission_id,
    site_id,
    report_title,
    report_type,
    report_status,
    generated_by,
    approved_by,
    summary,
    audience,
    interpretation,
    limitations,
    recommendations,
    formats,
    created_at,
    updated_at
) ON TABLE app.reports TO mangroscan_api_rw;

COMMIT;
