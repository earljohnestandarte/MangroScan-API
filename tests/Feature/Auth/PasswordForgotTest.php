<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PasswordForgotTest extends TestCase
{
    use RefreshDatabase;

    // [AUTH-06] Active accounts receive a reset token and exact accepted response with audit evidence.
    public function test_it_sends_password_reset_instructions(): void
    {
        Notification::fake();
        $user = $this->createUser();
        $response = $this->withHeader('X-Request-ID', 'req_auth_06_success')
            ->postJson('/api/v1/auth/password/forgot', ['email' => ' PASSWORD@EXAMPLE.TEST ']);

        $response->assertStatus(202)->assertHeader('X-Request-ID', 'req_auth_06_success')
            ->assertExactJson([
                'data' => ['message' => 'Password reset instructions have been sent.'],
                'meta' => ['request_id' => 'req_auth_06_success'],
            ]);
        $row = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        $this->assertNotNull($row);
        $this->assertNotEmpty($row->token);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification): bool {
            $this->assertNotSame($notification->token, DB::table('password_reset_tokens')->value('token'));
            $this->assertTrue(Hash::check($notification->token, DB::table('password_reset_tokens')->value('token')));
            $mail = $notification->toMail(User::query()->firstOrFail());
            $this->assertStringStartsWith('http://localhost:5173/reset-password?', $mail->actionUrl);
            $this->assertStringContainsString('email=password%40example.test', $mail->actionUrl);

            return true;
        });

        $audit = AuditLog::query()->sole();
        $this->assertSame('auth.password.reset.requested', $audit->action);
        $this->assertSame($user->user_id, $audit->record_id);
        $this->assertSame(['delivery' => 'email'], $audit->new_values);
        $this->assertStringNotContainsString((string) $row->token, $audit->toJson());
    }

    // [AUTH-06] Unknown and inactive accounts follow the documented 404 without persistence.
    public function test_it_hides_unavailable_accounts(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'missing@example.test'])->assertNotFound();
        $user = $this->createUser();
        DB::table('users')->where('user_id', $user->user_id)->update(['status' => 'inactive']);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertNotFound();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
    }

    // [AUTH-06] Broker throttle returns 409 and preserves one reset request.
    public function test_it_rejects_a_recent_duplicate_request(): void
    {
        Notification::fake();
        $user = $this->createUser();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertStatus(202);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    // [AUTH-06] Email syntax and presence are validated.
    public function test_it_validates_the_email(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'not-an-email'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email'], 'error.details');
    }

    // [AUTH-06] Audit failure rolls back the reset token before notification delivery.
    public function test_it_rolls_back_when_audit_fails(): void
    {
        Notification::fake();
        $user = $this->createUser();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertInternalServerError();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
    }

    // [AUTH-06] Public reset requests share the login credential throttle.
    public function test_it_rate_limits_reset_requests(): void
    {
        config(['mangroscan.auth.login_attempts_per_minute' => 1]);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'missing@example.test'])->assertNotFound();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'missing@example.test'])
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [AUTH-06] Reset-token DCL is runtime-only and excludes reporting/worker roles.
    public function test_it_versions_password_reset_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/016_password_reset_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE app.password_reset_tokens TO mangroscan_api_rw;', $dcl);
        $this->assertStringNotContainsString('mangroscan_report_ro', $dcl);
        $this->assertStringNotContainsString('mangroscan_worker', $dcl);
    }

    private function createUser(): User
    {
        $org = (string) Str::uuid();
        $user = (string) Str::uuid();
        DB::table('organizations')->insert(['organization_id' => $org, 'organization_name' => 'Reset Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert(['user_id' => $user, 'organization_id' => $org, 'first_name' => 'Password', 'last_name' => 'Reset', 'email' => 'password@example.test', 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return User::findOrFail($user);
    }
}
