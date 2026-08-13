\set ON_ERROR_STOP on

BEGIN;

GRANT UPDATE (
    quality_score,
    quality_status,
    notes,
    sync_version,
    updated_at
) ON TABLE app.media_assets TO mangroscan_api_rw;

COMMIT;
