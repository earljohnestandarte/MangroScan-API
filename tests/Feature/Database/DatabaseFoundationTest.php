<?php

namespace Tests\Feature\Database;

use Tests\TestCase;

class DatabaseFoundationTest extends TestCase
{
    public function test_dcl_bootstrap_defines_every_documented_role_as_no_login(): void
    {
        $sql = file_get_contents(database_path('sql/dcl/001_roles_and_schema.sql'));

        $this->assertIsString($sql);

        foreach ([
            'mangroscan_owner',
            'mangroscan_migrator',
            'mangroscan_api_rw',
            'mangroscan_worker',
            'mangroscan_report_ro',
            'mangroscan_auditor',
            'mangroscan_backup',
        ] as $role) {
            $this->assertStringContainsString("ALTER ROLE {$role} NOLOGIN;", $sql);
        }

        $this->assertStringNotContainsString('PASSWORD', strtoupper($sql));
        $this->assertStringContainsString('CREATE SCHEMA IF NOT EXISTS app AUTHORIZATION mangroscan_owner;', $sql);
        $this->assertStringContainsString('REVOKE CREATE ON SCHEMA public FROM PUBLIC;', $sql);
        $this->assertStringContainsString('REVOKE ALL ON SCHEMA app FROM PUBLIC;', $sql);
    }

    public function test_postgresql_extension_migration_is_version_controlled(): void
    {
        $migration = file_get_contents(database_path(
            'migrations/2026_08_12_061500_enable_postgresql_extensions.php',
        ));

        $this->assertIsString($migration);
        $this->assertStringContainsString('CREATE EXTENSION IF NOT EXISTS "pgcrypto"', $migration);
        $this->assertStringContainsString('CREATE EXTENSION IF NOT EXISTS postgis', $migration);
        $this->assertStringContainsString("DB::getDriverName() !== 'pgsql'", $migration);
    }

    public function test_example_environment_uses_a_non_owner_postgresql_runtime_account(): void
    {
        $environment = file_get_contents(base_path('.env.example'));

        $this->assertIsString($environment);
        $this->assertStringContainsString('DB_CONNECTION=pgsql', $environment);
        $this->assertStringContainsString('DB_PORT=5432', $environment);
        $this->assertStringContainsString('DB_USERNAME=mangroscan_api', $environment);
        $this->assertStringContainsString('DB_SEARCH_PATH="app,public"', $environment);
        $this->assertStringNotContainsString('DB_USERNAME=postgres', $environment);
        $this->assertStringNotContainsString('DB_USERNAME=mangroscan_owner', $environment);

        $this->assertSame('app,public', config('database.connections.pgsql.search_path'));
    }

    public function test_postgresql_profile_is_pinned_to_the_dedicated_test_database(): void
    {
        $profile = file_get_contents(base_path('phpunit.pgsql.xml'));

        $this->assertIsString($profile);
        $this->assertStringContainsString(
            '<env name="DB_CONNECTION" value="pgsql" force="true"/>',
            $profile,
        );
        $this->assertStringContainsString(
            '<env name="DB_DATABASE" value="mangroscan_test" force="true"/>',
            $profile,
        );
        $this->assertStringNotContainsString('DB_DATABASE" value="mangroscan"', $profile);
        $this->assertStringContainsString('.env.testing', file_get_contents(base_path('.gitignore')));
    }

    public function test_postgresql_test_provisioning_has_safe_passwordless_defaults(): void
    {
        $sql = file_get_contents(database_path('sql/testing/001_create_test_database.sql'));

        $this->assertIsString($sql);
        $this->assertStringContainsString('\\set test_database mangroscan_test', $sql);
        $this->assertStringContainsString('\\set test_owner mangroscan_test', $sql);
        $this->assertStringContainsString('CREATE EXTENSION IF NOT EXISTS postgis;', $sql);
        $this->assertStringNotContainsString('PASSWORD', strtoupper($sql));
        $this->assertStringNotContainsString('mangroscan;', $sql);
    }
}
