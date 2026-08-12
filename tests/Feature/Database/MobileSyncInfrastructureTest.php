<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileSyncInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_provisions_the_mobile_sync_ledger_and_versions(): void
    {
        $this->assertTrue(Schema::hasColumns('sync_requests', [
            'sync_request_id',
            'device_id',
            'idempotency_key',
            'request_hash',
            'response_payload',
            'completed_at',
        ]));
        $this->assertTrue(Schema::hasColumns('sync_change_log', [
            'sync_change_id',
            'device_id',
            'client_id',
            'client_version',
            'payload_hash',
            'result_status',
            'server_id',
            'server_version',
            'result_payload',
        ]));
        $this->assertTrue(Schema::hasColumns('sync_conflicts', [
            'sync_conflict_id',
            'sync_change_id',
            'device_id',
            'client_id',
            'conflict_code',
            'client_payload',
            'server_payload',
        ]));
        $this->assertTrue(Schema::hasColumn('flight_sessions', 'sync_version'));

        $migration = file_get_contents(database_path('migrations/2026_08_12_063900_create_mobile_sync_ledger_tables.php'));
        $this->assertIsString($migration);
        $this->assertStringContainsString("->unique(['device_id', 'idempotency_key'])", $migration);
        $this->assertStringContainsString("->unique(['device_id', 'client_id'])", $migration);
        $this->assertStringContainsString('sync_change_log_result_status_check', $migration);
        $this->assertStringContainsString("\$table->unsignedBigInteger('sync_version')->default(1);", $migration);
    }

    public function test_it_versions_least_privilege_sync_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/011_mobile_sync_grants.sql'));

        $this->assertIsString($dcl);
        $this->assertStringContainsString(
            'GRANT SELECT, INSERT, UPDATE ON TABLE app.sync_requests TO mangroscan_api_rw;',
            $dcl,
        );
        $this->assertStringContainsString(
            'GRANT SELECT, INSERT ON TABLE app.sync_change_log, app.sync_conflicts TO mangroscan_api_rw;',
            $dcl,
        );
        $this->assertStringNotContainsString('mangroscan_report_ro', $dcl);
        $this->assertStringNotContainsString('mangroscan_worker', $dcl);

    }
}
