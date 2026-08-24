\set ON_ERROR_STOP on

BEGIN;

GRANT INSERT (
    validation_match_id,
    validation_session_id,
    ground_truth_id,
    tree_observation_id,
    match_status,
    accepted_species_id,
    accepted_height_m,
    accepted_age_years,
    corrected_geometry,
    notes,
    validation_evidence,
    distance_error_meters,
    species_correct,
    height_error_meters,
    age_error_years,
    validated_by,
    validated_at
) ON TABLE app.validation_matches TO mangroscan_api_rw;

GRANT UPDATE (
    final_species_id,
    final_height_meters,
    final_estimated_age_years,
    tree_location,
    validation_status,
    updated_at
) ON TABLE app.tree_observations TO mangroscan_api_rw;

COMMIT;
