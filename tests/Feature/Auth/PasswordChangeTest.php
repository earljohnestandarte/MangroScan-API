<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    // [AUTH-05] Password change returns 204, revokes credentials, and writes secret-free audit evidence.
    public function test_it_changes_password_and_revokes_all_tokens(): void
    {
        $identity = $this->createIdentity();
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$identity['token'],
            'X-Request-ID' => 'req_auth_05_success',
            'User-Agent' => 'MangroScan Password Test',
        ])->putJson('/api/v1/auth/password', $this->payload());

        $response->assertNoContent()->assertHeader('X-Request-ID', 'req_auth_05_success');
        $this->assertSame('', $response->getContent());
        $this->assertTrue(Hash::check('New-password1!Secure', User::findOrFail($identity['user_id'])->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $audit = AuditLog::query()->sole();
        $this->assertSame('auth.password.changed', $audit->action);
        $this->assertSame($identity['user_id'], $audit->record_id);
        $this->assertSame(2, $audit->new_values['credentials_revoked']);
        $this->assertStringNotContainsString('correct-password', $audit->toJson());
        $this->assertStringNotContainsString('New-password1!Secure', $audit->toJson());
    }

    // [AUTH-05] An incorrect current password is a field validation failure without side effects.
    public function test_it_rejects_an_incorrect_current_password(): void
    {
        $identity = $this->createIdentity();
        $payload = $this->payload();
        $payload['current_password'] = 'wrong-password';
        $this->withToken($identity['token'])->putJson('/api/v1/auth/password', $payload)
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['current_password'], 'error.details');
        $this->assertTrue(Hash::check('correct-password', User::findOrFail($identity['user_id'])->password));
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [AUTH-05] Confirmation, strength, and password reuse rules are enforced.
    public function test_it_validates_new_password_contract(): void
    {
        $identity = $this->createIdentity();
        $this->withToken($identity['token'])->putJson('/api/v1/auth/password', [
            'current_password' => 'correct-password',
            'new_password' => 'correct-password',
            'new_password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['new_password'], 'error.details');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [AUTH-05] Authentication and active identity are mandatory.
    public function test_it_enforces_authentication_and_active_identity(): void
    {
        $this->putJson('/api/v1/auth/password', $this->payload())->assertUnauthorized();
        $identity = $this->createIdentity();
        DB::table('users')->where('user_id', $identity['user_id'])->update(['status' => 'inactive']);
        $this->withToken($identity['token'])->putJson('/api/v1/auth/password', $this->payload())
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [AUTH-05] Mandatory audit failure rolls back both password and credential revocation.
    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $identity = $this->createIdentity();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($identity['token'])->putJson('/api/v1/auth/password', $this->payload())->assertInternalServerError();
        $this->assertTrue(Hash::check('correct-password', User::findOrFail($identity['user_id'])->password));
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    // [AUTH-05] Attempts share the authenticated request budget.
    public function test_it_rate_limits_password_change_attempts(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $identity = $this->createIdentity();
        $payload = $this->payload();
        $payload['current_password'] = 'wrong-password';
        $this->withToken($identity['token'])->putJson('/api/v1/auth/password', $payload)->assertUnprocessable();
        $this->withToken($identity['other_token'])->putJson('/api/v1/auth/password', $this->payload())
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    // [AUTH-05] Existing least-privilege identity DCL supports the transactional update.
    public function test_it_uses_versioned_identity_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/002_identity_and_audit_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('app.users,', $dcl);
        $this->assertStringContainsString('app.personal_access_tokens', $dcl);
        $this->assertStringContainsString('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE', $dcl);
        $this->assertStringContainsString('REVOKE UPDATE, DELETE, TRUNCATE ON TABLE app.audit_logs', $dcl);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return ['current_password' => 'correct-password', 'new_password' => 'New-password1!Secure', 'new_password_confirmation' => 'New-password1!Secure'];
    }

    /** @return array<string, string> */
    private function createIdentity(): array
    {
        $org = (string) Str::uuid();
        $userId = (string) Str::uuid();
        DB::table('organizations')->insert(['organization_id' => $org, 'organization_name' => 'Password Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert(['user_id' => $userId, 'organization_id' => $org, 'first_name' => 'Password', 'last_name' => 'User', 'email' => 'password@example.test', 'password' => Hash::make('correct-password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::findOrFail($userId);
        $token = $user->createToken('Current')->plainTextToken;
        $other = $user->createToken('Other')->plainTextToken;

        return ['user_id' => $userId, 'token' => $token, 'other_token' => $other];
    }
}
