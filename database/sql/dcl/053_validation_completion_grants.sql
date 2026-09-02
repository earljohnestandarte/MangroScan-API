\set ON_ERROR_STOP on

BEGIN;

REVOKE UPDATE ON TABLE app.validation_sessions FROM mangroscan_api_rw;

GRANT UPDATE (
    status,
    notes,
    completed_at,
    completed_by,
    updated_at
) ON TABLE app.validation_sessions TO mangroscan_api_rw;

COMMIT;
