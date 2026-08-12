\set ON_ERROR_STOP on

BEGIN;

GRANT INSERT (retry_of_job_id, retry_reason) ON TABLE app.processing_jobs TO mangroscan_api_rw;

COMMIT;
