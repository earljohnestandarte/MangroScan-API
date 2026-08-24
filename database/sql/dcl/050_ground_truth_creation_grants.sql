\set ON_ERROR_STOP on

BEGIN;

GRANT INSERT (
    ground_truth_id,
    validation_session_id,
    field_code,
    species_id,
    ground_location,
    measured_height_meters,
    estimated_age_years,
    diameter_cm,
    crown_diameter_m,
    health_status,
    is_tree,
    photo_path,
    remarks,
    created_at
) ON TABLE app.ground_truth_tree_records TO mangroscan_api_rw;

COMMIT;
