\set ON_ERROR_STOP on

BEGIN;

GRANT UPDATE (encrypted_api_key, updated_at)
ON TABLE app.ai_services TO mangroscan_api_rw;

GRANT UPDATE (is_deployed, release_notes, updated_at)
ON TABLE app.ai_model_versions TO mangroscan_api_rw;

GRANT UPDATE (job_status, cancelled_at, cancelled_by, cancellation_reason, updated_at)
ON TABLE app.processing_jobs TO mangroscan_api_rw;

GRANT UPDATE (run_status)
ON TABLE app.model_runs TO mangroscan_api_rw;

COMMIT;
