\set ON_ERROR_STOP on

BEGIN;

GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE
    app.organizations,
    app.users,
    app.roles,
    app.permissions,
    app.role_permissions,
    app.user_roles,
    app.personal_access_tokens
TO mangroscan_api_rw;

GRANT SELECT, INSERT ON TABLE app.audit_logs TO mangroscan_api_rw;
REVOKE UPDATE, DELETE, TRUNCATE ON TABLE app.audit_logs FROM mangroscan_api_rw, mangroscan_worker;

GRANT SELECT ON TABLE app.audit_logs TO mangroscan_auditor;

COMMIT;
