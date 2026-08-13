\set ON_ERROR_STOP on

BEGIN;

REVOKE ALL ON FUNCTION app.fn_touch_updated_at()
FROM PUBLIC, mangroscan_api_rw, mangroscan_worker, mangroscan_report_ro, mangroscan_auditor;

COMMIT;
