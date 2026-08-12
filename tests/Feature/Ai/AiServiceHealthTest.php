<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiServiceHealthTest extends TestCase
{
    use RefreshDatabase;

    // [AISVC-03] A successful probe uses the server key and persists safe health evidence.
    public function test_it_health_tests_a_registered_service(): void
    {
        Http::fake([
            'https://inference.example.test/health' => Http::response([
                'status' => 'ok', 'version' => ' 2.4.1 ',
            ]),
        ]);
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_aisvc_03')
            ->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/test');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_aisvc_03')
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.version', '2.4.1')
            ->assertJsonPath('meta.request_id', 'req_aisvc_03');
        $this->assertIsInt($response->json('data.latency_ms'));
        $this->assertGreaterThanOrEqual(0, $response->json('data.latency_ms'));
        $this->assertSame(['status', 'version', 'latency_ms'], array_keys($response->json('data')));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://inference.example.test/health'
            && $request->hasHeader('X-API-Key', 'server-only-key')
            && $request->hasHeader('Accept', 'application/json')
        );
        $this->assertDatabaseHas('ai_services', [
            'ai_service_id' => $graph['service_id'],
            'health_status' => 'healthy',
            'service_version' => '2.4.1',
            'last_health_latency_ms' => $response->json('data.latency_ms'),
        ]);
        $this->assertNotNull(DB::table('ai_services')->where('ai_service_id', $graph['service_id'])->value('last_health_checked_at'));

        $audit = DB::table('audit_logs')->where('action', 'ai_service.health_test')->first();
        $this->assertNotNull($audit);
        $this->assertSame('req_aisvc_03', $audit->request_id);
        $this->assertStringNotContainsString('server-only-key', (string) $audit->new_values);
        $this->assertStringNotContainsString('encrypted_api_key', (string) $audit->new_values);
    }

    // [AISVC-03] Missing, malformed and disabled services never trigger a request.
    public function test_it_rejects_unavailable_service_records(): void
    {
        Http::fake();
        $graph = $this->createGraph(enabled: false);

        $this->withToken($graph['token'])->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/test')
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.details.enabled', false);
        $this->withToken($graph['token'])->postJson('/api/v1/admin/ai-services/'.Str::uuid().'/test')
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->withToken($graph['token'])->postJson('/api/v1/admin/ai-services/not-a-uuid/test')
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [AISVC-03] Authentication and a tenant-valid administrator grant are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        Http::fake();
        $graph = $this->createGraph(prefix: 'auth-');
        $this->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/test')->assertUnauthorized();

        $missing = $this->createGraph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->postJson('/api/v1/admin/ai-services/'.$missing['service_id'].'/test')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'ai_services.manage');

        $foreign = $this->createGraph(foreignPermission: true, prefix: 'foreign-');
        $this->withToken($foreign['token'])->postJson('/api/v1/admin/ai-services/'.$foreign['service_id'].'/test')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'ai_services.manage');

        Http::assertNothingSent();
    }

    // [AISVC-03] Inactive administrators cannot call a registered backend.
    public function test_it_rejects_an_inactive_identity(): void
    {
        Http::fake();
        $inactive = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($inactive['token'])->postJson('/api/v1/admin/ai-services/'.$inactive['service_id'].'/test')
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
        Http::assertNothingSent();
    }

    // [AISVC-03] Invalid successful payloads map to 502 and persist unavailable evidence.
    public function test_it_maps_invalid_health_payloads_to_bad_gateway(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_bad_gateway')
            ->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/test')
            ->assertStatus(502)->assertJsonPath('error.code', 'BAD_GATEWAY')
            ->assertJsonPath('error.request_id', 'req_bad_gateway');

        $this->assertDatabaseHas('ai_services', [
            'ai_service_id' => $graph['service_id'], 'health_status' => 'unavailable',
            'service_version' => null, 'last_health_latency_ms' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai_service.health_test', 'request_id' => 'req_bad_gateway']);
    }

    // [AISVC-03] Downstream HTTP and transport failures map to 503 without leaking secrets.
    public function test_it_maps_downstream_failures_to_service_unavailable(): void
    {
        foreach (['http', 'transport'] as $case) {
            Http::fake(function () use ($case) {
                if ($case === 'transport') {
                    throw new ConnectionException('Connection failed with server-only-key.');
                }

                return Http::response(['detail' => 'X-API-Key server-only-key rejected'], 500);
            });
            $graph = $this->createGraph(prefix: $case.'-');
            $response = $this->withToken($graph['token'])
                ->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/test');

            $response->assertServiceUnavailable()->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
            $this->assertStringNotContainsString('server-only-key', $response->getContent());
            $this->assertDatabaseHas('ai_services', [
                'ai_service_id' => $graph['service_id'], 'health_status' => 'unavailable',
            ]);
        }
    }

    // [AISVC-03] Health tests share the authenticated request budget.
    public function test_it_rate_limits_health_tests(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        Http::fake(['*/health' => Http::response(['status' => 'healthy', 'version' => '1.0'])]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/test')->assertOk();
        $this->withToken($graph['token'])->postJson('/api/v1/admin/ai-services/'.$graph['service_id'].'/test')
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
        Http::assertSentCount(1);
    }

    // [AISVC-03] The API decrypts through a narrow function and updates health columns only.
    public function test_it_versions_narrow_secret_read_and_health_update_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_064800_add_health_latency_and_secret_reader_to_ai_services.php'));
        $dcl = file_get_contents(database_path('sql/dcl/029_ai_service_health_grants.sql'));

        $this->assertIsString($migration);
        foreach (['last_health_latency_ms', 'SECURITY DEFINER', 'ai_service_encrypted_key'] as $object) {
            $this->assertStringContainsString($object, $migration);
        }
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT EXECUTE ON FUNCTION app.ai_service_encrypted_key(uuid)', $dcl);
        $this->assertStringContainsString('GRANT UPDATE (', $dcl);
        foreach (['encrypted_api_key', 'mangroscan_report_ro', 'mangroscan_worker', 'INSERT', 'DELETE'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    /** @return array<string, string> */
    private function createGraph(
        bool $permission = true,
        bool $foreignPermission = false,
        bool $enabled = true,
        string $prefix = '',
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Health Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Health Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actorId, $organizationId, $prefix.'health@example.test');
        $this->user($foreignUserId, $foreignOrganizationId, $prefix.'foreign-health@example.test');

        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRoleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Health Admin', 'role_code' => $prefix.'health_admin', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Health Admin', 'role_code' => $prefix.'foreign_health_admin', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'ai_services.manage')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore([
            'permission_id' => $permissionId, 'permission_code' => 'ai_services.manage',
            'permission_name' => 'Manage AI services', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($permission || $foreignPermission) {
            $roleId = $foreignPermission ? $foreignRoleId : $localRoleId;
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actorId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        }

        $serviceId = (string) Str::uuid();
        DB::table('ai_services')->insert([
            'ai_service_id' => $serviceId, 'service_name' => $prefix.'Inference',
            'base_url' => 'https://'.($prefix === '' ? '' : rtrim($prefix, '-').'.').'inference.example.test',
            'encrypted_api_key' => Crypt::encryptString('server-only-key'),
            'environment' => $prefix === '' ? 'production' : substr($prefix, 0, 49),
            'enabled' => $enabled, 'health_status' => 'unknown', 'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'actor_id' => $actorId,
            'service_id' => $serviceId,
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'ai-health')->plainTextToken,
        ];
    }

    private function user(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId,
            'first_name' => 'AI', 'last_name' => 'Health', 'email' => $email,
            'password' => Hash::make('password'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
