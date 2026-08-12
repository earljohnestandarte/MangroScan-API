<?php

namespace Tests\Feature\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SanctumTokenInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_issues_hashed_uuid_backed_bearer_tokens_for_uuid_users(): void
    {
        $organizationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('organizations')->insert([
            'organization_id' => $organizationId,
            'organization_name' => 'MangroScan Test Organization',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'first_name' => 'Test',
            'last_name' => 'Researcher',
            'email' => 'researcher@example.test',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->findOrFail($userId);
        $expiresAt = now()->startOfSecond()->addHour();
        $issued = $user->createToken('Expo test device', ['*'], $expiresAt);
        [$tokenId, $plainTextSecret] = explode('|', $issued->plainTextToken, 2);

        $this->assertTrue(Str::isUuid($tokenId));
        $this->assertNotSame('', $plainTextSecret);

        $token = PersonalAccessToken::query()->findOrFail($tokenId);

        $this->assertSame($userId, $token->tokenable_id);
        $this->assertSame(User::class, $token->tokenable_type);
        $this->assertSame('Expo test device', $token->name);
        $this->assertSame(hash('sha256', $plainTextSecret), $token->token);
        $this->assertNotSame($plainTextSecret, $token->token);
        $this->assertTrue($token->expires_at->equalTo($expiresAt));
        $this->assertTrue($token->tokenable->is($user));
    }
}
