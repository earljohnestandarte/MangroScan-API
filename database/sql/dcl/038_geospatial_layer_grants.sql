\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT ON TABLE app.photogrammetry_products, app.geospatial_layers
    TO mangroscan_api_rw, mangroscan_report_ro;
GRANT SELECT ON TABLE app.photogrammetry_products, app.geospatial_layers
    TO mangroscan_worker;

COMMIT;
