\set ON_ERROR_STOP on
BEGIN;
GRANT UPDATE (completion_idempotency_key, completion_fingerprint, checksum_sha256,
    upload_status, completed_at, sensor_dataset_id, updated_at)
    ON TABLE app.sensor_dataset_upload_sessions TO mangroscan_api_rw;
GRANT INSERT (sensor_dataset_id, flight_session_id, sensor_id, dataset_type,
    file_name, storage_key, file_format, spatial_reference, metadata,
    quality_status, created_at, updated_at) ON TABLE app.sensor_datasets TO mangroscan_api_rw;
REVOKE SELECT ON TABLE app.sensor_datasets FROM mangroscan_api_rw;
GRANT SELECT (sensor_dataset_id, flight_session_id, sensor_id, dataset_type,
    file_name, file_format, recorded_start_at, recorded_end_at,
    spatial_reference, metadata, quality_status, created_at, updated_at, deleted_at)
    ON TABLE app.sensor_datasets TO mangroscan_api_rw;
COMMIT;
