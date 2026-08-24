<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use App\Services\Auth\EffectiveAccessService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeedData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DroneOperatorRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mangroscan.seed_user_password' => 'password']);
    }

    // [AUTH-01, AUTH-02, AUTH-08] The deterministic Drone Operator account has only field permissions.
    public function test_drone_operator_is_seeded_idempotently_with_exact_effective_access(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $operator = User::query()->where('email', 'operator@mangroscan.test')->sole();
        $access = app(EffectiveAccessService::class)->rolesAndPermissions($operator);
        $expected = RbacSeedData::ROLE_PERMISSIONS['drone_operator'];
        sort($expected);

        $this->assertSame('30000000-0000-4000-8000-000000000004', $operator->user_id);
        $this->assertSame(RbacSeedData::ORGANIZATION_ID, $operator->organization_id);
        $this->assertSame('active', $operator->status);
        $this->assertNotNull($operator->email_verified_at);
        $this->assertTrue(Hash::check('password', $operator->password));
        $this->assertSame(['Drone Operator'], $access['roles']);
        $this->assertSame($expected, $access['permissions']);
        $this->assertDatabaseCount('users', count(RbacSeedData::USERS));
        $this->assertDatabaseCount('roles', count(RbacSeedData::ROLES));
        $this->assertDatabaseCount('user_roles', count(RbacSeedData::USERS));

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'operator@mangroscan.test',
            'password' => 'password',
            'device_name' => 'Drone Operator field device',
        ])->assertOk()
            ->assertJsonPath('data.roles', ['Drone Operator'])
            ->assertJsonPath('data.permissions', $expected);

        $this->withToken((string) $login->json('data.access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'operator@mangroscan.test')
            ->assertJsonPath('data.roles', ['Drone Operator']);
    }

    // [MSN-01, FLT-03, CHK-01, FLT-05, FLT-06, SYNC-02, SYNC-03] Field access is assignment-scoped.
    public function test_drone_operator_can_run_only_assigned_approved_field_workflows(): void
    {
        $this->seed(DatabaseSeeder::class);
        $graph = $this->fieldGraph();
        $token = $this->loginOperator();

        $this->withToken($token)->getJson('/api/v1/missions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mission_id', $graph['assigned_mission']);

        $this->withToken($token)->getJson('/api/v1/mobile/bootstrap')
            ->assertOk()
            ->assertJsonCount(1, 'data.missions')
            ->assertJsonCount(1, 'data.flights')
            ->assertJsonPath('data.missions.0.mission_id', $graph['assigned_mission'])
            ->assertJsonPath('data.flights.0.flight_session_id', $graph['assigned_flight']);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/missions/'.$graph['assigned_mission'].'/bundle')
            ->assertOk()
            ->assertJsonCount(1, 'data.flights')
            ->assertJsonPath('data.flights.0.flight_session_id', $graph['assigned_flight']);

        $this->withToken($token)->getJson('/api/v1/flights/'.$graph['assigned_flight'])
            ->assertOk()
            ->assertJsonPath('data.flight.flight_session_id', $graph['assigned_flight']);

        foreach (['unassigned_mission', 'pending_mission', 'foreign_mission'] as $mission) {
            $this->withToken($token)
                ->getJson('/api/v1/mobile/missions/'.$graph[$mission].'/bundle')
                ->assertNotFound();
        }

        foreach (['unassigned_flight', 'pending_flight', 'foreign_flight'] as $flight) {
            $this->withToken($token)
                ->getJson('/api/v1/flights/'.$graph[$flight])
                ->assertNotFound();
        }

        $checklist = [
            'checklist_type' => 'pre_flight',
            'battery_ok' => true,
            'weather_ok' => true,
            'gps_ok' => true,
            'camera_ok' => true,
            'lidar_depth_ok' => true,
            'storage_ok' => true,
            'overall_status' => 'passed',
            'remarks' => 'Operator preflight passed',
        ];
        $this->withToken($token)
            ->postJson('/api/v1/flights/'.$graph['assigned_flight'].'/checklists', $checklist)
            ->assertCreated();
        $this->withToken($token)
            ->postJson('/api/v1/flights/'.$graph['assigned_flight'].'/start', [
                'started_at' => '2026-08-24T01:00:00Z',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'flying');
        $this->withToken($token)
            ->postJson('/api/v1/flights/'.$graph['assigned_flight'].'/complete', [
                'ended_at' => '2026-08-24T01:30:00Z',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->withToken($token)->getJson('/api/v1/notifications')->assertOk();
    }

    // [USR-01, MSN-02, MSN-06, JOB-01, ACC-01] Management and scientific APIs stay forbidden.
    public function test_drone_operator_is_denied_admin_research_and_validation_apis(): void
    {
        $this->seed(DatabaseSeeder::class);
        $token = $this->loginOperator();

        $denied = [
            ['GET', '/api/v1/users', 'users.manage'],
            ['POST', '/api/v1/missions', 'missions.create'],
            ['POST', '/api/v1/missions/'.Str::uuid().'/approve', 'missions.approve'],
            ['GET', '/api/v1/processing-jobs', 'processing_jobs.manage'],
            ['POST', '/api/v1/validation-sessions/'.Str::uuid().'/accuracy/recompute', 'accuracy.recompute'],
        ];

        foreach ($denied as [$method, $uri, $permission]) {
            $this->withToken($token)
                ->json($method, $uri)
                ->assertForbidden()
                ->assertJsonPath('error.details.required_permission', $permission);
        }
    }

    /** @return array<string, string> */
    private function fieldGraph(): array
    {
        $operator = User::query()->where('email', 'operator@mangroscan.test')->sole();
        $admin = User::query()->where('email', 'admin@mangroscan.test')->sole();
        $researcher = User::query()->where('email', 'researcher@mangroscan.test')->sole();
        $foreignOrganization = (string) Str::uuid();
        $foreignUser = (string) Str::uuid();

        DB::table('organizations')->insert([
            'organization_id' => $foreignOrganization,
            'organization_name' => 'Foreign Drone Operator Organization',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'user_id' => $foreignUser,
            'organization_id' => $foreignOrganization,
            'first_name' => 'Foreign',
            'last_name' => 'Pilot',
            'email' => 'foreign-operator@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $localSite = (string) Str::uuid();
        $foreignSite = (string) Str::uuid();
        $this->site($localSite, RbacSeedData::ORGANIZATION_ID, $researcher->user_id, 'OP-SITE');
        $this->site($foreignSite, $foreignOrganization, $foreignUser, 'FOREIGN-OP-SITE');

        $graph = [
            'assigned_mission' => (string) Str::uuid(),
            'unassigned_mission' => (string) Str::uuid(),
            'pending_mission' => (string) Str::uuid(),
            'foreign_mission' => (string) Str::uuid(),
            'assigned_flight' => (string) Str::uuid(),
            'unassigned_flight' => (string) Str::uuid(),
            'pending_flight' => (string) Str::uuid(),
            'foreign_flight' => (string) Str::uuid(),
        ];

        $this->mission($graph['assigned_mission'], $localSite, $researcher->user_id, $admin->user_id, 'OP-ASSIGNED');
        $this->mission($graph['unassigned_mission'], $localSite, $researcher->user_id, $admin->user_id, 'OP-UNASSIGNED');
        $this->mission($graph['pending_mission'], $localSite, $researcher->user_id, null, 'OP-PENDING');
        $this->mission($graph['foreign_mission'], $foreignSite, $foreignUser, $foreignUser, 'FOREIGN-OP');

        DB::table('mission_team_members')->insert([
            'mission_team_id' => (string) Str::uuid(),
            'mission_id' => $graph['assigned_mission'],
            'user_id' => $operator->user_id,
            'team_role' => 'pilot',
            'assigned_at' => now(),
        ]);
        DB::table('mission_team_members')->insert([
            'mission_team_id' => (string) Str::uuid(),
            'mission_id' => $graph['pending_mission'],
            'user_id' => $operator->user_id,
            'team_role' => 'pilot',
            'assigned_at' => now(),
        ]);

        $localDrone = (string) Str::uuid();
        $foreignDrone = (string) Str::uuid();
        $this->drone($localDrone, RbacSeedData::ORGANIZATION_ID, 'OP-DRONE');
        $this->drone($foreignDrone, $foreignOrganization, 'FOREIGN-OP-DRONE');
        $this->flight($graph['assigned_flight'], $graph['assigned_mission'], $localDrone, $operator->user_id, 'OP-FLT-ASSIGNED');
        $this->flight($graph['unassigned_flight'], $graph['unassigned_mission'], $localDrone, $researcher->user_id, 'OP-FLT-UNASSIGNED');
        $this->flight($graph['pending_flight'], $graph['pending_mission'], $localDrone, $operator->user_id, 'OP-FLT-PENDING');
        $this->flight($graph['foreign_flight'], $graph['foreign_mission'], $foreignDrone, $foreignUser, 'FOREIGN-OP-FLT');

        return $graph;
    }

    private function site(string $id, string $organization, string $creator, string $code): void
    {
        DB::table('survey_sites')->insert([
            'site_id' => $id,
            'organization_id' => $organization,
            'site_name' => $code,
            'site_code' => $code,
            'province' => 'Palawan',
            'city_municipality' => 'Puerto Princesa',
            'environment_type' => 'mangrove',
            'status' => 'active',
            'created_by' => $creator,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mission(string $id, string $site, string $creator, ?string $approver, string $code): void
    {
        DB::table('survey_missions')->insert([
            'mission_id' => $id,
            'site_id' => $site,
            'mission_code' => $code,
            'mission_title' => $code,
            'mission_objective' => 'Drone Operator field workflow',
            'mission_status' => 'planned',
            'created_by' => $creator,
            'approved_by' => $approver,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function drone(string $id, string $organization, string $serial): void
    {
        DB::table('drones')->insert([
            'drone_id' => $id,
            'organization_id' => $organization,
            'drone_name' => $serial,
            'serial_number' => $serial,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function flight(string $id, string $mission, string $drone, string $pilot, string $code): void
    {
        DB::table('flight_sessions')->insert([
            'flight_session_id' => $id,
            'mission_id' => $mission,
            'drone_id' => $drone,
            'pilot_user_id' => $pilot,
            'flight_code' => $code,
            'flight_status' => 'planned',
            'quality_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function loginOperator(): string
    {
        $this->app['auth']->forgetGuards();

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'operator@mangroscan.test',
            'password' => 'password',
            'device_name' => 'Drone Operator test',
        ])->assertOk()->json('data.access_token');
    }
}
