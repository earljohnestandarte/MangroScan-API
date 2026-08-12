\set ON_ERROR_STOP on

\if :{?test_database}
\else
    \set test_database mangroscan_test
\endif

\if :{?test_owner}
\else
    \set test_owner mangroscan_test
\endif

SELECT format('CREATE ROLE %I LOGIN', :'test_owner')
WHERE NOT EXISTS (
    SELECT 1 FROM pg_roles WHERE rolname = :'test_owner'
) \gexec

SELECT format('CREATE DATABASE %I OWNER %I', :'test_database', :'test_owner')
WHERE NOT EXISTS (
    SELECT 1 FROM pg_database WHERE datname = :'test_database'
) \gexec

\connect :test_database

CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS postgis;

CREATE SCHEMA IF NOT EXISTS app AUTHORIZATION :"test_owner";
ALTER SCHEMA app OWNER TO :"test_owner";

GRANT ALL PRIVILEGES ON DATABASE :"test_database" TO :"test_owner";
GRANT ALL PRIVILEGES ON SCHEMA app TO :"test_owner";
