\set ON_ERROR_STOP on
BEGIN;
GRANT SELECT, INSERT ON TABLE app.sensor_dataset_upload_sessions TO mangroscan_api_rw;
COMMIT;
