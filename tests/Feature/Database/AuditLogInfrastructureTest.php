<?php

namespace Tests\Feature\Database;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class AuditLogInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_uuid_audit_evidence_and_request_context(): void
    {
        $recordId = (string) Str::uuid();

        $audit = AuditLog::query()->create([
            'action' => 'auth.failed',
            'table_name' => 'users',
            'record_id' => $recordId,
            'new_values' => ['email_hash' => hash('sha256', 'user@example.test')],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'MangroScan test client',
            'request_id' => 'req_audit_test',
        ])->refresh();

        $this->assertTrue(Str::isUuid($audit->audit_log_id));
        $this->assertSame('auth.failed', $audit->action);
        $this->assertSame($recordId, $audit->record_id);
        $this->assertSame('req_audit_test', $audit->request_id);
        $this->assertArrayHasKey('email_hash', $audit->new_values);
        $this->assertNotNull($audit->created_at);
    }

    public function test_eloquent_cannot_update_an_audit_log(): void
    {
        $audit = $this->createAuditLog();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit logs are append-only.');

        $audit->update(['action' => 'tampered']);
    }

    public function test_eloquent_cannot_delete_an_audit_log(): void
    {
        $audit = $this->createAuditLog();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit logs are append-only.');

        $audit->delete();
    }

    public function test_postgresql_and_dcl_guards_are_version_controlled(): void
    {
        $migration = file_get_contents(database_path(
            'migrations/2026_08_12_062300_create_audit_logs_table.php',
        ));
        $dcl = file_get_contents(database_path('sql/dcl/002_identity_and_audit_grants.sql'));

        $this->assertIsString($migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString('trg_audit_logs_append_only', $migration);
        $this->assertStringContainsString('fn_reject_audit_mutation', $migration);
        $this->assertStringContainsString(
            'REVOKE UPDATE, DELETE, TRUNCATE ON TABLE app.audit_logs',
            $dcl,
        );
    }

    private function createAuditLog(): AuditLog
    {
        return AuditLog::query()->create([
            'action' => 'auth.login',
            'table_name' => 'users',
            'record_id' => (string) Str::uuid(),
        ]);
    }
}
