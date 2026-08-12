\set ON_ERROR_STOP on

BEGIN;

DO $bootstrap_roles$
DECLARE
    role_name text;
BEGIN
    FOREACH role_name IN ARRAY ARRAY[
        'mangroscan_owner',
        'mangroscan_migrator',
        'mangroscan_api_rw',
        'mangroscan_worker',
        'mangroscan_report_ro',
        'mangroscan_auditor',
        'mangroscan_backup'
    ]
    LOOP
        IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = role_name) THEN
            EXECUTE format('CREATE ROLE %I NOLOGIN', role_name);
        END IF;
    END LOOP;
END;
$bootstrap_roles$;

ALTER ROLE mangroscan_owner NOLOGIN;
ALTER ROLE mangroscan_migrator NOLOGIN;
ALTER ROLE mangroscan_api_rw NOLOGIN;
ALTER ROLE mangroscan_worker NOLOGIN;
ALTER ROLE mangroscan_report_ro NOLOGIN;
ALTER ROLE mangroscan_auditor NOLOGIN;
ALTER ROLE mangroscan_backup NOLOGIN;

GRANT mangroscan_owner TO mangroscan_migrator;

CREATE SCHEMA IF NOT EXISTS app AUTHORIZATION mangroscan_owner;
ALTER SCHEMA app OWNER TO mangroscan_owner;

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
REVOKE ALL ON SCHEMA app FROM PUBLIC;

GRANT USAGE, CREATE ON SCHEMA app TO mangroscan_migrator;
GRANT USAGE ON SCHEMA app TO
    mangroscan_api_rw,
    mangroscan_worker,
    mangroscan_report_ro,
    mangroscan_auditor,
    mangroscan_backup;

COMMIT;
