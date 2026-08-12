\set ON_ERROR_STOP on

BEGIN;

GRANT INSERT (
    model_id,
    ai_service_id,
    external_model_key,
    model_name,
    model_type,
    framework,
    description,
    created_by,
    created_at,
    updated_at,
    deleted_at
) ON TABLE app.ai_models TO mangroscan_api_rw;

GRANT UPDATE (
    model_name,
    model_type,
    framework,
    description,
    updated_at,
    deleted_at
) ON TABLE app.ai_models TO mangroscan_api_rw;

GRANT INSERT (
    model_version_id,
    model_id,
    version_label,
    model_file_path,
    training_dataset_id,
    accuracy,
    precision_score,
    recall_score,
    f1_score,
    rmse,
    release_notes,
    created_at,
    updated_at
) ON TABLE app.ai_model_versions TO mangroscan_api_rw;

GRANT UPDATE (
    model_file_path,
    accuracy,
    precision_score,
    recall_score,
    f1_score,
    rmse,
    release_notes,
    updated_at
) ON TABLE app.ai_model_versions TO mangroscan_api_rw;

GRANT UPDATE (
    capabilities,
    last_synchronized_at,
    updated_at
) ON TABLE app.ai_services TO mangroscan_api_rw;

COMMIT;
