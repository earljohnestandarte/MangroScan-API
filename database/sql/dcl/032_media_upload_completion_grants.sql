\set ON_ERROR_STOP on

BEGIN;

GRANT UPDATE (
    completion_idempotency_key,
    completion_fingerprint,
    upload_status,
    completed_at,
    media_asset_id,
    updated_at
) ON TABLE app.media_upload_sessions TO mangroscan_api_rw;

GRANT INSERT (
    media_asset_id,
    flight_session_id,
    uploaded_by_user_id,
    file_name,
    file_type,
    mime_type,
    file_size_bytes,
    storage_key,
    checksum_sha256,
    capture_location,
    captured_at,
    metadata,
    quality_status,
    processing_status,
    created_at,
    updated_at
) ON TABLE app.media_assets TO mangroscan_api_rw;

COMMIT;
