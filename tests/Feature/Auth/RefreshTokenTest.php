<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\RefreshToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    // [AUTH-04] A refresh credential is single-use and rotates both credentials.
    public function test_it_rotates_a_refresh_token(): void
    {
        $this->createIdentity();
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'refresh@example.test',
            'password' => 'correct-password',
            'device_name' => 'Original client',
        ])->assertOk();
        $oldPlain = $login->json('data.refresh_token');

        $response = $this->withHeaders([
            'X-Request-ID' => 'req_auth_04_rotate',
            'User-Agent' => 'MangroScan Refresh Test',
        ])->postJson('/api/v1/auth/refresh', ['refresh_token' => $oldPlain]);

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_auth_04_rotate')
            ->assertJsonPath('data.user.email', 'refresh@example.test')
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.permissions', [])
            ->assertJsonPath('meta.request_id', 'req_auth_04_rotate');

        $payload = $response->json('data');
        $this->assertSame(
            ['user', 'access_token', 'expires_at', 'refresh_token', 'roles', 'permissions'],
            array_keys($payload),
        );
        $this->assertNotSame($oldPlain, $payload['refresh_token']);

        $oldToken = RefreshToken::query()
            ->where('token_hash', hash('sha256', $oldPlain))
            ->sole();
        $newToken = RefreshToken::query()
            ->where('token_hash', hash('sha256', $payload['refresh_token']))
            ->sole();
        $this->assertNotNull($oldToken->revoked_at);
        $this->assertSame($newToken->refresh_token_id, $oldToken->replaced_by);
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $audit = AuditLog::query()->where('action', 'auth.refresh')->sole();
        $this->assertSame($oldToken->refresh_token_id, $audit->record_id);
        $this->assertSame('req_auth_04_rotate', $audit->request_id);
        $this->assertStringNotContainsString($oldPlain, $audit->toJson());
        $this->assertStringNotContainsString($payload['refresh_token'], $audit->toJson());

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $oldPlain])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->assertDatabaseCount('refresh_tokens', 2);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    // [AUTH-04] Missing and unknown refresh credentials use standard API errors.
    public function test_it_rejects_invalid_refresh_credentials(): void
    {
        $this->withHeader('X-Request-ID', 'req_auth_04_validation')
            ->postJson('/api/v1/auth/refresh')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['refresh_token'], 'error.details');

        $this->withHeader('X-Request-ID', 'req_auth_04_invalid')
            ->postJson('/api/v1/auth/refresh', ['refresh_token' => 'unknown-refresh-token'])
            ->assertUnauthorized()
            ->assertExactJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication is required.',
                    'details' => [],
                    'request_id' => 'req_auth_04_invalid',
                ],
            ]);
    }

    private function createIdentity(): void
    {
        $organizationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('organizations')->insert([
            'organization_id' => $organizationId,
            'organization_name' => 'Refresh Token Organization',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'first_name' => 'Refresh',
            'last_name' => 'User',
            'email' => 'refresh@example.test',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
