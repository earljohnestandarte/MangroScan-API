<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TouchUpdatedAtTriggerTest extends TestCase
{
    use RefreshDatabase;

    // [R-01] Every current mutable application table with updated_at has one database trigger.
    public function test_postgresql_trigger_coverage_matches_the_current_schema(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL trigger metadata requires PostgreSQL.');
        }

        $timestampTables = collect(DB::select(<<<'SQL'
            SELECT table_name
            FROM information_schema.columns
            WHERE table_schema = 'app'
                AND column_name = 'updated_at'
            ORDER BY table_name
            SQL))->pluck('table_name');

        $triggerTables = collect(DB::select(<<<'SQL'
            SELECT relation.relname AS table_name
            FROM pg_catalog.pg_trigger AS trigger
            INNER JOIN pg_catalog.pg_class AS relation ON relation.oid = trigger.tgrelid
            INNER JOIN pg_catalog.pg_namespace AS namespace ON namespace.oid = relation.relnamespace
            INNER JOIN pg_catalog.pg_proc AS routine ON routine.oid = trigger.tgfoid
            INNER JOIN pg_catalog.pg_namespace AS routine_namespace ON routine_namespace.oid = routine.pronamespace
            WHERE namespace.nspname = 'app'
                AND routine_namespace.nspname = 'app'
                AND routine.proname = 'fn_touch_updated_at'
                AND NOT trigger.tgisinternal
            ORDER BY relation.relname
            SQL))->pluck('table_name');

        $this->assertCount(36, $timestampTables);
        $this->assertSame($timestampTables->all(), $triggerTables->all());
    }

    // [R-01] Raw updates cannot retain a stale caller-supplied updated_at value.
    public function test_postgresql_trigger_sets_the_database_timestamp(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL trigger execution requires PostgreSQL.');
        }

        $organizationId = (string) Str::uuid();
        $stale = Carbon::parse('2000-01-01T00:00:00Z');

        DB::table('organizations')->insert([
            'organization_id' => $organizationId,
            'organization_name' => 'R-01 Trigger Organization',
            'status' => 'active',
            'created_at' => $stale,
            'updated_at' => $stale,
        ]);

        DB::table('organizations')->where('organization_id', $organizationId)->update([
            'status' => 'inactive',
            'updated_at' => $stale,
        ]);

        $updatedAt = DB::table('organizations')
            ->where('organization_id', $organizationId)
            ->value('updated_at');

        $this->assertNotNull($updatedAt);
        $this->assertTrue(Carbon::parse($updatedAt)->isAfter($stale));
    }

    // [R-01] Trigger DDL is rerunnable, invoker-rights and unavailable as a direct runtime API.
    public function test_it_versions_the_trigger_function_and_closed_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_070200_create_touch_updated_at_triggers.php'));
        $dcl = file_get_contents(database_path('sql/dcl/043_touch_updated_at_trigger_grants.sql'));

        $this->assertIsString($migration);
        foreach (['CREATE OR REPLACE FUNCTION app.fn_touch_updated_at()', 'RETURNS trigger', 'SECURITY INVOKER', 'SET search_path = pg_catalog', 'NEW.updated_at := statement_timestamp()', 'BEFORE UPDATE ON app.{$table}', 'REVOKE ALL'] as $fragment) {
            $this->assertStringContainsString($fragment, $migration);
        }
        $this->assertStringNotContainsString('SECURITY DEFINER', $migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString('REVOKE ALL ON FUNCTION app.fn_touch_updated_at()', $dcl);
        foreach (['PUBLIC', 'mangroscan_api_rw', 'mangroscan_worker', 'mangroscan_report_ro', 'mangroscan_auditor'] as $role) {
            $this->assertStringContainsString($role, $dcl);
        }
        $this->assertStringNotContainsString('GRANT EXECUTE', $dcl);
    }
}
