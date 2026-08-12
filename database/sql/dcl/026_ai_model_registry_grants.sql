\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT ON TABLE app.ai_models, app.ai_model_versions
TO mangroscan_api_rw, mangroscan_report_ro;

COMMIT;
