<?php

namespace Tests\Feature\Rbac;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeedData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VerifyDeveloperQaUsersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mangroscan.seed_user_password' => 'qa-browser-secret']);
    }

    public function test_it_verifies_all_seeded_browser_qa_identities_without_printing_the_password(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Artisan::call('mangroscan:qa-users:verify'));
        $output = Artisan::output();

        foreach (array_keys(RbacSeedData::USERS) as $email) {
            $this->assertStringContainsString($email, $output);
        }
        $this->assertStringNotContainsString('qa-browser-secret', $output);
        $this->assertStringContainsString('All role-specific browser-QA identities are ready.', $output);
    }

    public function test_it_fails_safely_when_the_password_is_not_configured(): void
    {
        config(['mangroscan.seed_user_password' => '']);

        $this->assertSame(1, Artisan::call('mangroscan:qa-users:verify'));
        $this->assertStringContainsString('MANGROSCAN_SEED_USER_PASSWORD is not configured.', Artisan::output());
    }

    public function test_it_detects_role_or_account_drift(): void
    {
        $this->seed(DatabaseSeeder::class);
        DB::table('user_roles')
            ->where('user_id', RbacSeedData::USERS['operator@mangroscan.test']['id'])
            ->delete();

        $this->assertSame(1, Artisan::call('mangroscan:qa-users:verify'));
        $this->assertStringContainsString('operator@mangroscan.test', Artisan::output());
    }
}
