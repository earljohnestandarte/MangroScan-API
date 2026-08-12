# PostgreSQL integration testing

The default `phpunit.xml` always uses an isolated SQLite in-memory database. PostgreSQL/PostGIS integration uses the separate `phpunit.pgsql.xml` profile, which forcibly targets a database named `mangroscan_test` so automated tests cannot select the normal `mangroscan` database.

## One-time provisioning

1. Copy `.env.testing.example` to `.env.testing` and provide only dedicated test credentials.
2. As a PostgreSQL administrator, create the `mangroscan_test` database and install `pgcrypto` and PostGIS, or grant its dedicated owner enough privilege for the extension migration.
3. Ensure the dedicated test user owns the database or may create the `app` schema. Normal production credentials must not be placed in `.env.testing`.

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
