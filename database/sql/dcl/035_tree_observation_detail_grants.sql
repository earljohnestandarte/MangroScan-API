\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT ON TABLE app.species_classification_results,
    app.canopy_height_estimations, app.age_estimations TO mangroscan_api_rw;

GRANT SELECT ON TABLE app.sensor_datasets, app.species_growth_models,
    app.species_classification_results, app.canopy_height_estimations,
    app.age_estimations TO mangroscan_report_ro;

GRANT SELECT ON TABLE app.sensor_datasets, app.species_growth_models,
    app.species_classification_results, app.canopy_height_estimations,
    app.age_estimations TO mangroscan_worker;
GRANT INSERT ON TABLE app.species_classification_results,
    app.canopy_height_estimations, app.age_estimations TO mangroscan_worker;

COMMIT;
