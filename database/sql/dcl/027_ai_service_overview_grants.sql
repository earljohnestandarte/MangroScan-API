\set ON_ERROR_STOP on

BEGIN;

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
    last_synchronized_at,
    created_by,
    created_at,
    updated_at
) ON TABLE app.ai_services TO mangroscan_api_rw;

COMMIT;
