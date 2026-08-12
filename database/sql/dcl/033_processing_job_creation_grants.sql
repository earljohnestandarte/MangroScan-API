\set ON_ERROR_STOP on

BEGIN;

GRANT INSERT (
    processing_job_id,
    mission_id,
    flight_session_id,
    job_type,
    job_status,
    input_summary,
    created_by,
    idempotency_key,
    request_fingerprint,
    created_at,
    updated_at
) ON TABLE app.processing_jobs TO mangroscan_api_rw;

GRANT INSERT (
    model_run_id,
    processing_job_id,
    model_version_id,
    run_type,
    input_media_id,
    parameters,
    run_status,
    created_at
) ON TABLE app.model_runs TO mangroscan_api_rw;
GRANT SELECT ON TABLE app.model_runs TO mangroscan_api_rw, mangroscan_report_ro;
GRANT UPDATE (processing_status, updated_at) ON TABLE app.media_assets TO mangroscan_api_rw;

GRANT SELECT ON TABLE app.processing_jobs, app.model_runs, app.media_assets,
    app.ai_models, app.ai_model_versions TO mangroscan_worker;
GRANT SELECT (
    ai_service_id,
    service_name,
    base_url,
    environment,
    enabled,
    health_status,
    service_version,
    capabilities,
    last_health_checked_at,
    last_health_latency_ms,
    last_synchronized_at
) ON TABLE app.ai_services TO mangroscan_worker;
GRANT UPDATE (job_status, output_summary, started_at, completed_at, error_message, updated_at)
    ON TABLE app.processing_jobs TO mangroscan_worker;
GRANT UPDATE (run_status, started_at, completed_at)
    ON TABLE app.model_runs TO mangroscan_worker;
GRANT UPDATE (processing_status, updated_at)
    ON TABLE app.media_assets TO mangroscan_worker;
GRANT EXECUTE ON FUNCTION app.ai_service_encrypted_key(uuid) TO mangroscan_worker;

COMMIT;
