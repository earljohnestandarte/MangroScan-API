<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    // [AUTH-07] A valid one-time token replaces the password, revokes sessions, and returns exact 204.
    public function test_it_resets_password_and_consumes_the_token(): void
    {
        $identity = $this->createIdentity();
        $token = Password::broker()->getRepository()->create($identity['user']);
        $response = $this->withHeader('X-Request-ID', 'req_auth_07_success')->postJson('/api/v1/auth/password/reset', $this->payload($token));
        $response->assertNoContent()->assertHeader('X-Request-ID', 'req_auth_07_success');
        $this->assertTrue(Hash::check('Reset-password1!Secure', User::findOrFail($identity['user']->user_id)->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $audit = AuditLog::query()->sole();
        $this->assertSame('auth.password.reset.completed', $audit->action);
        $this->assertSame(2, $audit->new_values['credentials_revoked']);
        $this->assertStringNotContainsString($token, $audit->toJson());
        $this->assertStringNotContainsString('Reset-password1!Secure', $audit->toJson());
    }

    // [AUTH-07] Invalid, expired, and consumed tokens use the standard documented 400.
    public function test_it_rejects_invalid_and_consumed_tokens(): void
    {
        $identity = $this->createIdentity();
        $this->postJson('/api/v1/auth/password/reset', $this->payload('invalid-token'))
            ->assertBadRequest()->assertJsonPath('error.code', 'BAD_REQUEST');
        $token = Password::broker()->getRepository()->create($identity['user']);
        $this->postJson('/api/v1/auth/password/reset', $this->payload($token))->assertNoContent();
        $this->postJson('/api/v1/auth/password/reset', $this->payload($token))->assertBadRequest();
    }

    // [AUTH-07] Unknown and inactive accounts remain generic 404 resources.
    public function test_it_hides_unavailable_accounts(): void
    {
        $this->postJson('/api/v1/auth/password/reset', [...$this->payload('token'), 'email' => 'missing@example.test'])->assertNotFound();
        $identity = $this->createIdentity();
        DB::table('users')->where('user_id', $identity['user']->user_id)->update(['status' => 'inactive']);
        $this->postJson('/api/v1/auth/password/reset', $this->payload('token'))->assertNotFound();
    }

    // [AUTH-07] Email, token, strength and confirmation are validated before lookup.
    public function test_it_validates_reset_input(): void
    {
        $this->postJson('/api/v1/auth/password/reset', ['token' => '', 'email' => 'bad', 'password' => 'weak', 'password_confirmation' => 'different'])
            ->assertUnprocessable()->assertJsonValidationErrors(['token', 'email', 'password'], 'error.details');
    }

    // [AUTH-07] Audit failure restores old password, reset token, and active credentials.
    public function test_it_rolls_back_when_audit_fails(): void
    {
        $identity = $this->createIdentity();
        $token = Password::broker()->getRepository()->create($identity['user']);
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->postJson('/api/v1/auth/password/reset', $this->payload($token))->assertInternalServerError();
        $this->assertTrue(Hash::check('correct-password', User::findOrFail($identity['user']->user_id)->password));
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    // [AUTH-07] Public reset completion shares the credential throttle.
    public function test_it_rate_limits_reset_completion(): void
    {
        config(['mangroscan.auth.login_attempts_per_minute' => 1]);
        $this->createIdentity();
        $this->postJson('/api/v1/auth/password/reset', $this->payload('invalid'))->assertBadRequest();
        $this->postJson('/api/v1/auth/password/reset', $this->payload('invalid'))->assertTooManyRequests();
    }

    // [AUTH-07] Completion reuses the versioned reset-token and identity DCL.
    public function test_it_uses_versioned_reset_and_identity_dcl(): void
    {
        $reset = file_get_contents(database_path('sql/dcl/016_password_reset_grants.sql'));
        $identity = file_get_contents(database_path('sql/dcl/002_identity_and_audit_grants.sql'));
        $this->assertStringContainsString('DELETE ON TABLE app.password_reset_tokens', $reset);
        $this->assertStringContainsString('app.personal_access_tokens', $identity);
        $this->assertStringContainsString('REVOKE UPDATE, DELETE, TRUNCATE ON TABLE app.audit_logs', $identity);
    }

    /** @return array<string, string> */
    private function payload(string $token): array
    {
        return ['token' => $token, 'email' => 'password@example.test', 'password' => 'Reset-password1!Secure', 'password_confirmation' => 'Reset-password1!Secure'];
    }

    /** @return array{user: User, token: string, other_token: string} */
    private function createIdentity(): array
    {
        $org = (string) Str::uuid();
        $id = (string) Str::uuid();
        DB::table('organizations')->insert(['organization_id' => $org, 'organization_name' => 'Reset Complete Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Password', 'last_name' => 'Reset', 'email' => 'password@example.test', 'password' => Hash::make('correct-password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::findOrFail($id);

        return ['user' => $user, 'token' => $user->createToken('Current')->plainTextToken, 'other_token' => $user->createToken('Other')->plainTextToken];
    }
}
