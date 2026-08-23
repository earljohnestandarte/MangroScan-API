\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT ON TABLE app.training_datasets, app.training_dataset_items
TO mangroscan_api_rw, mangroscan_report_ro;

GRANT INSERT ON TABLE app.training_datasets, app.training_dataset_items
TO mangroscan_api_rw;

GRANT SELECT ON TABLE app.annotation_projects, app.annotation_items, app.annotation_objects, app.annotation_exports
TO mangroscan_api_rw, mangroscan_report_ro;

GRANT INSERT ON TABLE app.annotation_projects, app.annotation_items, app.annotation_objects, app.annotation_exports
TO mangroscan_api_rw;

GRANT UPDATE (status, updated_at) ON TABLE app.annotation_items
TO mangroscan_api_rw;

GRANT DELETE ON TABLE app.annotation_objects
TO mangroscan_api_rw;

COMMIT;
