<?php

namespace Tests\Feature\Audit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsApiIdentity;
use Tests\TestCase;

class AuditLogShowTest extends TestCase
{
    use BuildsApiIdentity;
    use RefreshDatabase;

    // [AUD-02] Detail reads preserve the index endpoint's tenant boundary.
    public function test_it_returns_only_tenant_visible_audit_detail(): void
    {
        $identity = $this->apiIdentity(['audit.read']);
        $localId = $this->audit($identity['actor_id'], 'mission.approval');
        $foreignOrg = $this->organization('Foreign Audit Detail Org');
        $foreignUser = $this->user($foreignOrg, 'foreign-audit-detail@example.test');
        $foreignId = $this->audit($foreignUser, 'mission.create');

        $this->withToken($identity['token'])->withHeader('X-Request-ID', 'req_aud_02')
            ->getJson('/api/v1/audit-logs/'.$localId)->assertOk()
            ->assertJsonPath('data.audit_log_id', $localId)
            ->assertJsonPath('data.action', 'mission.approval')
            ->assertJsonPath('meta.request_id', 'req_aud_02');
        $this->withToken($identity['token'])->getJson('/api/v1/audit-logs/'.$foreignId)
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_audit_detail_requires_authentication_and_audit_permission(): void
    {
        $id = (string) Str::uuid();
        $this->getJson('/api/v1/audit-logs/'.$id)->assertUnauthorized();
        $identity = $this->apiIdentity([], 'no-audit-');
        $this->withToken($identity['token'])->getJson('/api/v1/audit-logs/'.$id)
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'audit.read');
    }

    private function audit(string $userId, string $action): string
    {
        $id = (string) Str::uuid();
        DB::table('audit_logs')->insert([
            'audit_log_id' => $id, 'user_id' => $userId, 'action' => $action,
            'table_name' => 'survey_missions', 'record_id' => (string) Str::uuid(),
            'old_values' => json_encode(['status' => 'planned']),
            'new_values' => json_encode(['status' => 'approved']),
            'request_id' => 'req_'.$id, 'created_at' => now(),
        ]);

        return $id;
    }
}
