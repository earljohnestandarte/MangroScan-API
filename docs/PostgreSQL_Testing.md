# PostgreSQL integration testing

The default `phpunit.xml` always uses an isolated SQLite in-memory database. PostgreSQL/PostGIS integration uses the separate `phpunit.pgsql.xml` profile, which forcibly targets a database named `mangroscan_test` so automated tests cannot select the normal `mangroscan` database.

## One-time provisioning

1. As a PostgreSQL administrator, run `psql -f database/sql/testing/001_create_test_database.sql`. Optional `-v test_database=... -v test_owner=...` values may select a different dedicated owner, but the PHPUnit profile intentionally remains pinned to `mangroscan_test`.
2. Assign the dedicated test owner a local password through your normal secret-management process when password authentication is required. The provisioning script intentionally contains no password.
3. Copy `.env.testing.example` to `.env.testing` and provide only the dedicated test credentials. Normal production credentials must not be placed in `.env.testing`.

The PostgreSQL migration creates `app` automatically only while `APP_ENV=testing`; production continues to require `database/sql/dcl/001_roles_and_schema.sql` so the schema has the documented `mangroscan_owner` ownership.

## Commands

Run the endpoint integration test:

```powershell
vendor\bin\phpunit -c phpunit.pgsql.xml tests\Feature\Auth\LoginTest.php
```

Run the complete PostgreSQL suite:

```powershell
vendor\bin\phpunit -c phpunit.pgsql.xml
```

Both commands use `RefreshDatabase` for database-backed feature tests and are destructive to `mangroscan_test`. They must never be pointed at production or a developer data database.
