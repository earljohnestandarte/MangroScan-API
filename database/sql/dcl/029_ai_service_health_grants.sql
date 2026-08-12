\set ON_ERROR_STOP on

BEGIN;

GRANT EXECUTE ON FUNCTION app.ai_service_encrypted_key(uuid) TO mangroscan_api_rw;

GRANT SELECT (last_health_latency_ms)
ON TABLE app.ai_services TO mangroscan_api_rw;

GRANT UPDATE (
    health_status,
    service_version,
    last_health_checked_at,
    last_health_latency_ms,
    updated_at
) ON TABLE app.ai_services TO mangroscan_api_rw;

COMMIT;
