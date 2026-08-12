\set ON_ERROR_STOP on

BEGIN;
GRANT SELECT, INSERT, DELETE ON TABLE app.mission_team_members TO mangroscan_api_rw;
GRANT SELECT ON TABLE app.mission_team_members TO mangroscan_report_ro;
COMMIT;
