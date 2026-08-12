<?php

namespace Tests\Feature\Drone;

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

class DroneStoreTest extends TestCase
{
    use RefreshDatabase;

    // [DRONE-02] Active authenticated users register normalized tenant-owned hardware.
    public function test_it_registers_a_drone_with_audit_evidence(): void
    {
        $g = $this->graph();
        $response = $this->withToken($g['token'])->withHeader('X-Request-ID', 'req_drone_02')
            ->postJson('/api/v1/drones', $this->payload());
        $response->assertCreated()->assertJsonPath('data.organization_id', $g['org'])
            ->assertJsonPath('data.drone_name', 'MangroScan Air One')->assertJsonPath('data.model', 'Mavic 3 Enterprise')
            ->assertJsonPath('data.serial_number', 'MS-AIR-001')->assertJsonPath('data.firmware_version', '1.2.3')
            ->assertJsonPath('data.max_flight_minutes', '42.50')->assertJsonPath('data.payload_capacity_grams', '850.25')
            ->assertJsonPath('data.status', 'available')->assertJsonPath('meta.request_id', 'req_drone_02');
        $audit = AuditLog::query()->sole();
        $this->assertSame('drone.create', $audit->action);
        $this->assertSame($response->json('data.drone_id'), $audit->record_id);
        $this->assertSame('MS-AIR-001', $audit->new_values['serial_number']);
    }

    // [DRONE-02] All documented optional hardware metadata may be omitted.
    public function test_it_accepts_omitted_optional_metadata(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/drones', ['drone_name' => 'Minimal', 'status' => 'maintenance'])
            ->assertCreated()->assertJsonPath('data.model', null)->assertJsonPath('data.serial_number', null)
            ->assertJsonPath('data.max_flight_minutes', null)->assertJsonPath('data.status', 'maintenance');
    }

    // [DRONE-02] Names, domains, lengths and positive physical capacities are validated.
    public function test_it_validates_drone_input(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/drones', [
            'drone_name' => ' ', 'serial_number' => str_repeat('x', 101), 'firmware_version' => str_repeat('x', 81),
            'max_flight_minutes' => 0, 'payload_capacity_grams' => '1000000', 'status' => 'flying',
        ])->assertUnprocessable()->assertJsonValidationErrors(['drone_name', 'serial_number', 'firmware_version', 'max_flight_minutes', 'payload_capacity_grams', 'status'], 'error.details');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [DRONE-02] Serial numbers remain globally reserved, including deleted units.
    public function test_it_rejects_reserved_serial_numbers(): void
    {
        $g = $this->graph();
        foreach ([' existing-serial ', ' deleted-serial '] as $serial) {
            $payload = $this->payload();
            $payload['serial_number'] = $serial;
            $this->withToken($g['token'])->postJson('/api/v1/drones', $payload)
                ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [DRONE-02] Audit failure rolls hardware persistence back.
    public function test_it_rolls_back_when_audit_fails(): void
    {
        $g = $this->graph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($g['token'])->postJson('/api/v1/drones', $this->payload())->assertInternalServerError();
        $this->assertDatabaseMissing('drones', ['serial_number' => 'MS-AIR-001']);
    }

    // [DRONE-02] Authentication and active user/organization state are mandatory.
    public function test_it_enforces_active_authentication_without_invented_permission(): void
    {
        $this->postJson('/api/v1/drones', $this->payload())->assertUnauthorized();
        $g = $this->graph();
        DB::table('users')->where('user_id', $g['actor'])->update(['status' => 'inactive']);
        $this->withToken($g['token'])->postJson('/api/v1/drones', $this->payload())
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_it_rate_limits_registration(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/drones', $this->payload())->assertCreated();
        $next = $this->payload();
        $next['serial_number'] = 'MS-AIR-002';
        $this->withToken($g['token'])->postJson('/api/v1/drones', $next)->assertTooManyRequests();
    }

    // [DRONE-02] Additive DCL grants only API INSERT.
    public function test_it_versions_insert_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/019_drone_write_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT INSERT ON TABLE app.drones TO mangroscan_api_rw;', $dcl);
        $this->assertStringNotContainsString('mangroscan_report_ro', $dcl);
        $this->assertStringNotContainsString('mangroscan_worker', $dcl);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['drone_name' => ' MangroScan Air One ', 'model' => ' Mavic 3 Enterprise ', 'serial_number' => ' ms-air-001 ', 'firmware_version' => ' 1.2.3 ', 'max_flight_minutes' => '42.50', 'payload_capacity_grams' => '850.25', 'status' => ' AVAILABLE '];
    }

    /** @return array<string, string> */
    private function graph(): array
    {
        $org = (string) Str::uuid();
        $actor = (string) Str::uuid();
        DB::table('organizations')->insert(['organization_id' => $org, 'organization_name' => 'Drone Registration', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert(['user_id' => $actor, 'organization_id' => $org, 'first_name' => 'Drone', 'last_name' => 'Registrar', 'email' => Str::uuid().'@test', 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert([
            ['drone_id' => (string) Str::uuid(), 'organization_id' => $org, 'drone_name' => 'Existing', 'serial_number' => 'EXISTING-SERIAL', 'status' => 'available', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null],
            ['drone_id' => (string) Str::uuid(), 'organization_id' => $org, 'drone_name' => 'Deleted', 'serial_number' => 'DELETED-SERIAL', 'status' => 'retired', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now()],
        ]);

        return ['org' => $org, 'actor' => $actor, 'token' => User::findOrFail($actor)->createToken('drone-store')->plainTextToken];
    }
}
