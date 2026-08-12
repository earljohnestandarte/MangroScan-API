\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT, INSERT ON TABLE app.survey_missions TO mangroscan_api_rw;
GRANT SELECT ON TABLE app.survey_missions TO mangroscan_report_ro;

COMMIT;
