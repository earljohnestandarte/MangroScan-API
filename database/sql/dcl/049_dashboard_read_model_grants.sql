\set ON_ERROR_STOP on

BEGIN;
REVOKE ALL ON TABLE app.v_mission_accuracy_summary FROM PUBLIC, mangroscan_api_rw, mangroscan_worker, mangroscan_report_ro, mangroscan_auditor;
REVOKE ALL ON TABLE app.mv_dashboard_mission_metrics FROM PUBLIC, mangroscan_api_rw, mangroscan_worker, mangroscan_report_ro, mangroscan_auditor;
GRANT SELECT ON TABLE app.v_mission_accuracy_summary, app.mv_dashboard_mission_metrics TO mangroscan_api_rw, mangroscan_report_ro;
COMMIT;
