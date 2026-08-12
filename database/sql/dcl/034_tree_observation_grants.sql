\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT ON TABLE app.mangrove_species, app.mangrove_tree_entities,
    app.tree_observations TO mangroscan_api_rw, mangroscan_report_ro;

GRANT SELECT ON TABLE app.mangrove_species, app.mangrove_tree_entities,
    app.tree_observations TO mangroscan_worker;

GRANT INSERT (
    tree_observation_id,
    tree_entity_id,
    mission_id,
    flight_session_id,
    model_run_id,
    source_media_id,
    tree_code,
    tree_location,
    crown_polygon,
    bounding_box,
    detection_confidence,
    final_species_id,
    final_height_meters,
    final_estimated_age_years,
    created_at,
    updated_at
) ON TABLE app.tree_observations TO mangroscan_worker;

GRANT UPDATE (
    crown_polygon,
    bounding_box,
    detection_confidence,
    final_species_id,
    final_height_meters,
    final_estimated_age_years,
    updated_at
) ON TABLE app.tree_observations TO mangroscan_worker;

COMMIT;
