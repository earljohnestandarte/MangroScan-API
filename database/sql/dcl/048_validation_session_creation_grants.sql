\set ON_ERROR_STOP on

BEGIN;

GRANT INSERT (
    validation_session_id,
    mission_id,
    site_id,
    plot_id,
    validated_by,
    validation_date,
    method,
    notes,
    created_at,
    updated_at
) ON TABLE app.validation_sessions TO mangroscan_api_rw;

COMMIT;
