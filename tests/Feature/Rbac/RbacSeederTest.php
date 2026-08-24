<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use App\Services\Auth\EffectiveAccessService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DeveloperUserSeeder;
use Database\Seeders\RbacSeedData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RbacSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mangroscan.seed_user_password' => 'password']);
    }

    public function test_seeders_create_the_deterministic_rbac_graph_idempotently(): void
    {
        $this->seed(DatabaseSeeder::class);

        $ids = [
            'organizations' => DB::table('organizations')->pluck('organization_id')->all(),
            'roles' => DB::table('roles')->orderBy('role_code')->pluck('role_id')->all(),
            'users' => DB::table('users')->orderBy('email')->pluck('user_id')->all(),
        ];
        $hashes = DB::table('users')->orderBy('email')->pluck('password')->all();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('permissions', count(RbacSeedData::PERMISSIONS));
        $this->assertDatabaseCount('roles', count(RbacSeedData::ROLES));
        $this->assertDatabaseCount('role_permissions', array_sum(array_map('count', RbacSeedData::ROLE_PERMISSIONS)));
        $this->assertDatabaseCount('users', count(RbacSeedData::USERS));
        $this->assertDatabaseCount('user_roles', count(RbacSeedData::USERS));
        $this->assertSame($ids['organizations'], DB::table('organizations')->pluck('organization_id')->all());
        $this->assertSame($ids['roles'], DB::table('roles')->orderBy('role_code')->pluck('role_id')->all());
        $this->assertSame($ids['users'], DB::table('users')->orderBy('email')->pluck('user_id')->all());
        $this->assertSame($hashes, DB::table('users')->orderBy('email')->pluck('password')->all());

        foreach (RbacSeedData::USERS as $email => $definition) {
            $user = User::query()->where('email', $email)->firstOrFail();
            $this->assertSame(RbacSeedData::ORGANIZATION_ID, $user->organization_id);
            $this->assertSame('active', $user->status);
            $this->assertNotNull($user->email_verified_at);
            $this->assertTrue(Hash::check('password', $user->password));
            $this->assertSame([$definition['role']], $user->roles()->pluck('role_code')->all());
        }
    }

    public function test_seeded_users_receive_the_exact_effective_permission_matrix(): void
    {
        $this->seed(DatabaseSeeder::class);
        $service = app(EffectiveAccessService::class);

        foreach (RbacSeedData::USERS as $email => $definition) {
            $access = $service->rolesAndPermissions(User::query()->where('email', $email)->firstOrFail());
            $expected = RbacSeedData::ROLE_PERMISSIONS[$definition['role']];
            sort($expected);

            $this->assertSame([RbacSeedData::ROLES[$definition['role']]['name']], $access['roles']);
            $this->assertSame($expected, $access['permissions']);
        }
    }

    // [AUTH-01, AUTH-02, AUTH-08] Every seeded role can authenticate and refresh its effective access.
    public function test_all_seeded_roles_can_login_and_read_their_effective_access(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (RbacSeedData::USERS as $email => $definition) {
            $login = $this->postJson('/api/v1/auth/login', [
                'email' => $email,
                'password' => 'password',
                'device_name' => 'RBAC seeder test',
            ])->assertOk()
                ->assertJsonPath('data.user.email', $email)
                ->assertJsonPath('data.roles.0', RbacSeedData::ROLES[$definition['role']]['name']);

            $token = $login->json('data.access_token');
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/auth/me')
                ->assertOk()
                ->assertJsonPath('data.user.email', $email)
                ->assertJsonPath('data.roles.0', RbacSeedData::ROLES[$definition['role']]['name']);
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/auth/permissions')
                ->assertOk()
                ->assertJsonPath('data.roles.0', RbacSeedData::ROLES[$definition['role']]['name']);
        }
    }

    // [AUD-01, SITE-01, RPT-01] Seeded roles pass representative positive and negative checks.
    public function test_seeded_roles_enforce_representative_endpoint_authorization(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->login('admin@mangroscan.test');
        $researcher = $this->login('researcher@mangroscan.test');
        $specialist = $this->login('specialist@mangroscan.test');

        $this->app['auth']->forgetGuards();
        $this->withToken($admin)->getJson('/api/v1/audit-logs')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($admin)->postJson('/api/v1/drones', [
            'drone_name' => 'Seeded Administrator Drone',
            'status' => 'available',
        ])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withToken($researcher)->getJson('/api/v1/sites')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($researcher)->getJson('/api/v1/drones')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($specialist)->getJson('/api/v1/reports')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($researcher)->getJson('/api/v1/audit-logs')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'audit.read');
        $this->app['auth']->forgetGuards();
        $this->withToken($specialist)->getJson('/api/v1/users')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'users.manage');
        $this->app['auth']->forgetGuards();
        $this->withToken($specialist)->getJson('/api/v1/drones')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'drones.read');
        $this->app['auth']->forgetGuards();
        $this->withToken($researcher)->postJson('/api/v1/drones', [
            'drone_name' => 'Unauthorized Researcher Drone',
            'status' => 'available',
        ])->assertForbidden()->assertJsonPath('error.details.required_permission', 'drones.manage');
        $this->app['auth']->forgetGuards();
        $this->withToken($admin)->getJson('/api/v1/missions')
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'missions.read');
    }

    // [SITE-03] A seeded Researcher cannot enumerate a foreign organization's site.
    public function test_seeded_researcher_cannot_read_a_foreign_organization_site(): void
    {
        $this->seed(DatabaseSeeder::class);
        $foreignOrganizationId = (string) Str::uuid();
        $foreignSiteId = (string) Str::uuid();

        DB::table('organizations')->insert([
            'organization_id' => $foreignOrganizationId,
            'organization_name' => 'Foreign RBAC Organization',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('survey_sites')->insert([
            'site_id' => $foreignSiteId,
            'organization_id' => $foreignOrganizationId,
            'site_name' => 'Foreign Site',
            'site_code' => 'RBAC-FOREIGN-SITE',
            'province' => 'Palawan',
            'city_municipality' => 'Puerto Princesa',
            'environment_type' => 'mangrove',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->login('researcher@mangroscan.test');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/sites/'.$foreignSiteId)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_database_seeding_never_creates_developer_users_in_production(): void
    {
        $this->seed(DatabaseSeeder::class);
        DB::table('user_roles')->delete();
        DB::table('users')->delete();
        $originalEnvironment = app()->environment();

        try {
            app()->detectEnvironment(fn (): string => 'production');
            app(DeveloperUserSeeder::class)->run();
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('roles', 4);
            $this->assertDatabaseCount('permissions', count(RbacSeedData::PERMISSIONS));
        } finally {
            app()->detectEnvironment(fn (): string => $originalEnvironment);
        }
    }

    private function login(string $email): string
    {
        $this->app['auth']->forgetGuards();

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'password',
            'device_name' => 'RBAC endpoint test',
        ])->assertOk()->json('data.access_token');
    }
}
