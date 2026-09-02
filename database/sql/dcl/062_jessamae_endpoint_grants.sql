\set ON_ERROR_STOP on

BEGIN;

GRANT UPDATE (
    drone_name, model, serial_number, firmware_version,
    max_flight_minutes, payload_capacity_grams, status, updated_at
) ON TABLE app.drones TO mangroscan_api_rw;
GRANT UPDATE (
    sensor_name, sensor_type, manufacturer, model, serial_number,
    resolution, range_meters, calibration_required, status, updated_at
) ON TABLE app.drone_sensors TO mangroscan_api_rw;
GRANT SELECT ON TABLE app.batteries TO mangroscan_api_rw, mangroscan_report_ro;
GRANT INSERT ON TABLE app.batteries, app.environment_logs, app.battery_usages TO mangroscan_api_rw;
GRANT UPDATE (status, updated_at) ON TABLE app.batteries TO mangroscan_api_rw;
GRANT UPDATE (sync_version, deleted_at, updated_at) ON TABLE app.media_assets TO mangroscan_api_rw;

COMMIT;
