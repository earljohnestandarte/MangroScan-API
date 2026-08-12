<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiServiceStoreTest extends TestCase
{
    use RefreshDatabase;

    // [AISVC-02] Registration encrypts the key and returns only safe service fields.
    public function test_it_registers_a_trusted_ai_service_with_encrypted_credentials(): void
    {
        Http::fake();
        $identity = $this->createIdentity();
        $response = $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_aisvc_02')
            ->withHeader('User-Agent', 'MangroScan Admin Test')
            ->postJson('/api/v1/admin/ai-services', [
                'service_name' => ' Primary FastAPI ',
                'base_url' => ' https://inference.example.test/v1/ ',
                'api_key' => ' server-only-secret ',
                'environment' => ' PRODUCTION ',
                'enabled' => true,
            ]);

        $response->assertCreated()->assertHeader('X-Request-ID', 'req_aisvc_02')
            ->assertJsonPath('data.service_name', 'Primary FastAPI')
            ->assertJsonPath('data.base_url', 'https://inference.example.test/v1')
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.health_status', 'unknown')
            ->assertJsonPath('data.service_version', null)
            ->assertJsonPath('data.capabilities', null)
            ->assertJsonPath('meta.request_id', 'req_aisvc_02');

        $this->assertSame([
            'ai_service_id', 'service_name', 'base_url', 'environment', 'enabled',
            'health_status', 'service_version', 'capabilities', 'last_health_checked_at',
            'last_synchronized_at', 'created_by', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));
        $this->assertArrayNotHasKey('api_key', $response->json('data'));
        $this->assertArrayNotHasKey('encrypted_api_key', $response->json('data'));

        $stored = DB::table('ai_services')->where('ai_service_id', $response->json('data.ai_service_id'))->first();
        $this->assertNotNull($stored);
        $this->assertNotSame('server-only-secret', $stored->encrypted_api_key);
        $this->assertSame('server-only-secret', Crypt::decryptString($stored->encrypted_api_key));

        $audit = DB::table('audit_logs')->where('action', 'ai_service.create')->first();
        $this->assertNotNull($audit);
        $this->assertSame($response->json('data.ai_service_id'), $audit->record_id);
        $this->assertSame('req_aisvc_02', $audit->request_id);
        $this->assertSame($identity['actor_id'], $audit->user_id);
        $this->assertStringNotContainsString('server-only-secret', (string) $audit->new_values);
        $this->assertStringNotContainsString('encrypted_api_key', (string) $audit->new_values);
        Http::assertNothingSent();
    }

    // [AISVC-02] Required types and safe base-URL syntax are validated before storage.
    public function test_it_validates_the_registration_contract(): void
    {
        $identity = $this->createIdentity();

        $this->withToken($identity['token'])->postJson('/api/v1/admin/ai-services', [
            'service_name' => '',
            'base_url' => 'https://user:password@example.test/path?secret=yes#fragment',
            'api_key' => '',
            'environment' => '',
            'enabled' => 'sometimes',
            'extra' => 'ignored',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(
                ['service_name', 'base_url', 'api_key', 'environment', 'enabled'],
                'error.details',
            );

        $this->assertDatabaseCount('ai_services', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [AISVC-02] Base URL and name/environment identities cannot be registered twice.
    public function test_it_returns_conflict_for_duplicate_service_identities(): void
    {
        $identity = $this->createIdentity();
        $payload = $this->payload();
        $this->withToken($identity['token'])->postJson('/api/v1/admin/ai-services', $payload)->assertCreated();

        $this->withToken($identity['token'])->postJson('/api/v1/admin/ai-services', [
            ...$payload, 'service_name' => 'Other Name', 'base_url' => 'HTTPS://INFERENCE.EXAMPLE.TEST',
        ])->assertConflict()->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.details.base_url', 'HTTPS://INFERENCE.EXAMPLE.TEST');

        $this->withToken($identity['token'])->postJson('/api/v1/admin/ai-services', [
            ...$payload, 'service_name' => ' primary fastapi ',
            'base_url' => 'https://other.example.test', 'environment' => ' Production ',
        ])->assertConflict()->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.details.environment', 'production');

        $this->assertDatabaseCount('ai_services', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [AISVC-02] Authentication and a current administrator permission are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $this->postJson('/api/v1/admin/ai-services', $this->payload())->assertUnauthorized();

        $missing = $this->createIdentity(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->postJson('/api/v1/admin/ai-services', $this->payload('missing'))
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'ai_services.manage');

        $foreign = $this->createIdentity(foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->postJson('/api/v1/admin/ai-services', $this->payload('foreign'))
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'ai_services.manage');

        $this->assertDatabaseCount('ai_services', 0);
    }

    // [AISVC-02] Inactive administrators cannot register backends.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $identity = $this->createIdentity(prefix: 'inactive-');
        DB::table('users')->where('user_id', $identity['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($identity['token'])->postJson('/api/v1/admin/ai-services', $this->payload('inactive'))
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
        $this->assertDatabaseCount('ai_services', 0);
    }

    // [AISVC-02] Registrations share the authenticated rate limit.
    public function test_it_rate_limits_ai_service_registration(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentity();

        $this->withToken($identity['token'])->postJson('/api/v1/admin/ai-services', $this->payload())->assertCreated();
        $this->withToken($identity['token'])->postJson('/api/v1/admin/ai-services', $this->payload('second'))
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertDatabaseCount('ai_services', 1);
    }

    // [AISVC-02] Registration adds insert privilege without exposing or mutating credentials.
    public function test_it_adds_only_the_registration_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/028_ai_service_registration_grants.sql'));

        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT INSERT (', $dcl);
        $this->assertStringContainsString('encrypted_api_key', $dcl);
        $this->assertStringContainsString('ON TABLE app.ai_services TO mangroscan_api_rw;', $dcl);
        foreach (['mangroscan_report_ro', 'mangroscan_worker', 'SELECT', 'UPDATE', 'DELETE'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    /** @return array<string, mixed> */
    private function payload(string $prefix = ''): array
    {
        return [
            'service_name' => ($prefix === '' ? '' : $prefix.' ').'Primary FastAPI',
            'base_url' => 'https://'.($prefix === '' ? '' : $prefix.'.').'inference.example.test',
            'api_key' => $prefix.'server-only-secret',
            'environment' => 'production',
            'enabled' => true,
        ];
    }

    /** @return array<string, string> */
    private function createIdentity(
        bool $permission = true,
        bool $foreignPermission = false,
        string $prefix = '',
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Service Store Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Service Store Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actorId, $organizationId, $prefix.'service-store@example.test');
        $this->user($foreignUserId, $foreignOrganizationId, $prefix.'foreign-service-store@example.test');

        $localRoleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $localRoleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Service Registrar', 'role_code' => $prefix.'service_registrar', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Service Registrar', 'role_code' => $prefix.'foreign_service_registrar', 'created_at' => now(), 'updated_at' => now()],
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

        return [
            'actor_id' => $actorId,
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'ai-store')->plainTextToken,
        ];
    }

    private function user(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId,
            'first_name' => 'AI', 'last_name' => 'Registrar', 'email' => $email,
            'password' => Hash::make('password'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
