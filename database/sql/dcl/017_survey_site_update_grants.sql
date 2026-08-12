\set ON_ERROR_STOP on

BEGIN;

GRANT UPDATE ON TABLE app.survey_sites TO mangroscan_api_rw;

COMMIT;
