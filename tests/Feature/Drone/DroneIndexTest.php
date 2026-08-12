<?php

namespace Tests\Feature\Drone;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DroneIndexTest extends TestCase
{
    use RefreshDatabase;

    // [DRONE-01] The list is paginated, side-effect free, and restricted to live tenant records.
    public function test_it_lists_only_current_organization_drones_with_safe_fields(): void
    {
        $graph = $this->createGraph();

        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_drone_01_success')
            ->getJson('/api/v1/drones?per_page=1&page=1');

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_drone_01_success')
            ->assertJsonPath('meta', [
                'request_id' => 'req_drone_01_success',
                'page' => 1,
                'per_page' => 1,
                'total' => 2,
                'last_page' => 2,
            ])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.drone_id', $graph['alpha_drone_id'])
            ->assertJsonPath('data.0.organization_id', $graph['organization_id'])
            ->assertJsonPath('data.0.max_flight_minutes', '42.50')
            ->assertJsonPath('data.0.payload_capacity_grams', '850.25');

        $this->assertSame([
            'drone_id',
            'organization_id',
            'drone_name',
            'model',
            'serial_number',
            'firmware_version',
            'max_flight_minutes',
            'payload_capacity_grams',
            'status',
            'created_at',
            'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [DRONE-01] Status and free-text filters compose after normalization.
    public function test_it_applies_drone_filters(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->getJson('/api/v1/drones?status=AVAILABLE&search=alpha-serial')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.drone_id', $graph['alpha_drone_id']);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/drones?status=maintenance&search=mavic')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.drone_id', $graph['beta_drone_id']);
    }

    // [DRONE-01] Unknown states and unsafe pagination bounds are rejected.
    public function test_it_validates_drone_filters(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_drone_01_validation')
            ->getJson('/api/v1/drones?status=flying&page=0&per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.request_id', 'req_drone_01_validation')
            ->assertJsonValidationErrors(['status', 'page', 'per_page'], 'error.details');
    }

    // [DRONE-01] Authentication is mandatory; no undocumented hardware permission is invented.
    public function test_it_requires_authentication_without_an_undocumented_permission(): void
    {
        $this->getJson('/api/v1/drones')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $graph = $this->createGraph();

        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('permissions', 0);
        $this->withToken($graph['token'])
            ->getJson('/api/v1/drones')
            ->assertOk();
    }

    // [DRONE-01] Inactive users and organizations cannot enumerate hardware.
    public function test_it_rejects_inactive_tenant_identities(): void
    {
        $inactiveUser = $this->createGraph();
        DB::table('users')->where('user_id', $inactiveUser['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($inactiveUser['token'])
            ->getJson('/api/v1/drones')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');

        $inactiveOrganization = $this->createGraph('second-');
        DB::table('organizations')
            ->where('organization_id', $inactiveOrganization['organization_id'])
            ->update(['status' => 'inactive']);

        $this->withToken($inactiveOrganization['token'])
            ->getJson('/api/v1/drones')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [DRONE-01] Drone reads share the authenticated request budget.
    public function test_it_rate_limits_drone_lists(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->getJson('/api/v1/drones')->assertOk();
        $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_drone_01_throttled')
            ->getJson('/api/v1/drones')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_drone_01_throttled');
    }

    // [DRONE-01] PostgreSQL guards the documented state domain and DCL stays least-privilege.
    public function test_it_versions_the_drone_database_guards(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_063500_create_drones_table.php'));
        $dcl = file_get_contents(database_path('sql/dcl/007_drone_grants.sql'));

        $this->assertIsString($migration);
        $this->assertStringContainsString('drones_status_check', $migration);
        $this->assertStringContainsString("'available', 'maintenance', 'retired'", $migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString(
            'GRANT SELECT ON TABLE app.drones TO mangroscan_api_rw, mangroscan_report_ro;',
            $dcl,
        );

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $graph = $this->createGraph('constraint-');

        $this->expectException(QueryException::class);
        $this->insertDrone(
            (string) Str::uuid(),
            $graph['organization_id'],
            'Invalid State Drone',
            'hovering',
        );
    }

    /**
     * @return array{
     *     actor_id: string,
     *     organization_id: string,
     *     alpha_drone_id: string,
     *     beta_drone_id: string,
     *     token: string
     * }
     */
    private function createGraph(string $prefix = ''): array
    {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();

        DB::table('organizations')->insert([
            [
                'organization_id' => $organizationId,
                'organization_name' => $prefix.'Current Organization',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => $foreignOrganizationId,
                'organization_name' => $prefix.'Foreign Organization',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('users')->insert([
            'user_id' => $actorId,
            'organization_id' => $organizationId,
            'first_name' => 'Drone',
            'last_name' => 'Operator',
            'email' => $prefix.'drone-operator@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $alphaDroneId = (string) Str::uuid();
        $betaDroneId = (string) Str::uuid();
        $this->insertDrone($alphaDroneId, $organizationId, 'Alpha Scout', 'available', 'Phantom 4', $prefix.'ALPHA-SERIAL', '42.50', '850.25');
        $this->insertDrone($betaDroneId, $organizationId, 'Beta Mapper', 'maintenance', 'Mavic 3');
        $this->insertDrone((string) Str::uuid(), $organizationId, 'Deleted Drone', 'retired', deleted: true);
        $this->insertDrone((string) Str::uuid(), $foreignOrganizationId, 'Foreign Drone', 'available');

        return [
            'actor_id' => $actorId,
            'organization_id' => $organizationId,
            'alpha_drone_id' => $alphaDroneId,
            'beta_drone_id' => $betaDroneId,
            'token' => User::query()
                ->findOrFail($actorId)
                ->createToken('Drone list test', ['*'], now()->addHour())
                ->plainTextToken,
        ];
    }

    private function insertDrone(
        string $droneId,
        string $organizationId,
        string $name,
        string $status,
        ?string $model = null,
        ?string $serialNumber = null,
        ?string $maxFlightMinutes = null,
        ?string $payloadCapacityGrams = null,
        bool $deleted = false,
    ): void {
        DB::table('drones')->insert([
            'drone_id' => $droneId,
            'organization_id' => $organizationId,
            'drone_name' => $name,
            'model' => $model,
            'serial_number' => $serialNumber,
            'firmware_version' => '1.2.3',
            'max_flight_minutes' => $maxFlightMinutes,
            'payload_capacity_grams' => $payloadCapacityGrams,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }
}
